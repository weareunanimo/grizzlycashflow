<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\RendimentoCategorizer;
use Grizzly\Application\Classification\RuleMatcher;
use Grizzly\Domain\Classification\MerchantNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Fila de revisão manual (needs_review = true em transactions ou card_purchases):
 * nenhuma regra reconheceu a categoria com confiança. Cada confirmação vira uma
 * regra nova (aprendizado incremental) e é replicada em todo lançamento igual
 * que também esteja pendente — não faz sentido revisar a mesma contraparte
 * várias vezes. Cobre banco e cartão, com filtro para ver só um dos dois.
 */
final class ReviewController extends Controller
{
    private const PER_PAGE = 20;

    /** Tamanho de `rules.name` — regras aprendidas truncam o nome pra caber. */
    private const RULE_NAME_MAX = 140;

    /** Teto do histórico lido para sugerir — segura o uso de memória. */
    private const HISTORY_LIMIT = 20000;

    public function index(Request $request): View
    {
        $userId = Auth::id();

        // Rendimento automático nunca deveria chegar à fila de revisão. Sem acesso
        // a shell no hosting, a própria tela conserta o histórico que entrou antes
        // dessa regra existir — o EXISTS é barato e depois disso não escreve mais.
        if ($this->hasPendingRendimentos($userId)) {
            RendimentoCategorizer::backfill($userId);
        }

        $accounts = DB::table('accounts')
            ->where('user_id', $userId)
            ->where('type', '!=', 'credit_card')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get(['id', 'name']);

        $cards = DB::table('credit_cards')
            ->join('accounts', 'accounts.id', '=', 'credit_cards.account_id')
            ->where('credit_cards.user_id', $userId)
            ->orderBy('credit_cards.id')
            ->get(['credit_cards.id', 'accounts.name as account_name']);

        $selectedAccountIds = array_map('intval', (array) $request->query('accounts', []));
        $selectedCardIds = array_map('intval', (array) $request->query('cards', []));

        $rows = $this->pendingTransactions($userId, $selectedAccountIds)
            ->concat($this->pendingPurchases($userId, $selectedCardIds))
            ->sortByDesc('sort_key')
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $slice = $rows->forPage($page, self::PER_PAGE)->values();

        $pending = new LengthAwarePaginator(
            $slice,
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        // Uma consulta só monta o histórico da página inteira, em vez de uma
        // consulta por lançamento como antes.
        $history = $this->categorizedHistory($userId);
        $rules = $this->activeRules($userId);

        $suggestions = [];
        foreach ($pending as $row) {
            $suggestions[$row->kind.'-'.$row->id] = $this->suggest($row->description, $history, $rules);
        }

        return view('review.index', [
            'pending' => $pending,
            'categories' => $this->categoryOptions($userId),
            'categoryNames' => $this->categoryNames($userId),
            'suggestions' => $suggestions,
            'accounts' => $accounts,
            'cards' => $cards,
            'selectedAccountIds' => $selectedAccountIds,
            'selectedCardIds' => $selectedCardIds,
        ]);
    }

    public function store(Request $request, string $kind, int $id): RedirectResponse
    {
        if (! in_array($kind, ['bank', 'card'], true)) {
            abort(404);
        }

        $userId = Auth::id();

        // Ou escolhe uma categoria existente, ou cria uma nova ali mesmo.
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'required_without:new_category'],
            'new_category' => ['nullable', 'string', 'max:80', 'required_without:category_id'],
        ], [], ['new_category' => 'nova categoria']);

        $table = $kind === 'bank' ? 'transactions' : 'card_purchases';
        $row = DB::table($table)->where('id', $id)->where('user_id', $userId)->first();

        if (! $row) {
            abort(404);
        }

        $newCategory = trim((string) ($validated['new_category'] ?? ''));

        $categoryId = $newCategory !== ''
            ? $this->findOrCreateCategory($userId, $newCategory)
            : (int) $validated['category_id'];

        if (! $this->categoryBelongsToUser($userId, $categoryId)) {
            abort(404);
        }

        // card_purchases não tem category_source/category_confidence (só transactions tem).
        $fields = ['category_id' => $categoryId, 'needs_review' => false, 'updated_at' => now()];
        if ($kind === 'bank') {
            $fields['category_source'] = 'user';
            $fields['category_confidence'] = 1.000;
        }

        DB::table($table)->where('id', $id)->update($fields);

        $replicated = DB::table($table)
            ->where('user_id', $userId)
            ->where('id', '!=', $id)
            ->where('needs_review', true)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(description) = LOWER(?)', [$row->description])
            ->update($fields);

        $this->learnRule($userId, $row->description, $categoryId);

        $name = DB::table('categories')->where('id', $categoryId)->value('name');

        $status = $replicated > 0
            ? "\"{$name}\" aplicada e replicada em mais {$replicated} lançamento(s) igual(is)."
            : "\"{$name}\" aplicada.";

        if ($newCategory !== '') {
            $status = "Categoria \"{$name}\" criada. ".$status;
        }

        return redirect()->route('review.index', $request->query())->with('status', $status);
    }

    /**
     * Cria a categoria só se ainda não existir com esse nome (comparação sem
     * acento/caixa via slug) — evita duplicar "Farmácia"/"farmacia" na revisão.
     */
    private function findOrCreateCategory(int $userId, string $name): int
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'categoria';
        }

        // Procura em qualquer nível: "Farmácia" existe como subcategoria de Saúde,
        // e criar uma "farmacia" solta no topo só duplicaria o relatório.
        $existing = DB::table('categories')
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('categories')->insertGetId([
            'user_id' => $userId,
            'parent_id' => null,
            'name' => $name,
            'slug' => $slug,
            'kind' => 'expense',
            'is_system' => false,
            'is_essential' => false,
            'sort_order' => 900, // criadas na revisão vão para o fim da lista
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hasPendingRendimentos(int $userId): bool
    {
        return DB::table('transactions')
            ->where('user_id', $userId)
            ->where('needs_review', true)
            ->whereNull('deleted_at')
            ->where('description', 'like', RendimentoCategorizer::DESCRIPTION_PREFIX.'%')
            ->exists();
    }

    private function categoryBelongsToUser(int $userId, int $categoryId): bool
    {
        return DB::table('categories')
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->exists();
    }

    /** @param list<int> $accountIds */
    private function pendingTransactions(int $userId, array $accountIds): Collection
    {
        return DB::table('transactions')
            ->where('user_id', $userId)
            ->where('needs_review', true)
            ->whereNull('deleted_at')
            ->when($accountIds !== [], fn ($q) => $q->whereIn('account_id', $accountIds))
            ->orderByDesc('occurred_on')
            ->get()
            ->map(fn ($tx) => (object) [
                'kind' => 'bank',
                // (int) obrigatório: o MySQL do hosting devolve colunas numéricas
                // como string, e daí pra frente tudo que espera int quebraria.
                'id' => (int) $tx->id,
                'date' => $tx->occurred_on,
                'description' => (string) $tx->description,
                'amount_cents' => $tx->direction === 'out' ? -((int) $tx->amount_cents) : (int) $tx->amount_cents,
                'sort_key' => $tx->occurred_on.'-'.str_pad((string) $tx->id, 12, '0', STR_PAD_LEFT),
            ]);
    }

    /** @param list<int> $cardIds */
    private function pendingPurchases(int $userId, array $cardIds): Collection
    {
        return DB::table('card_purchases')
            ->where('user_id', $userId)
            ->where('needs_review', true)
            ->whereNull('deleted_at')
            ->when($cardIds !== [], fn ($q) => $q->whereIn('credit_card_id', $cardIds))
            ->orderByDesc('purchase_date')
            ->get()
            ->map(fn ($p) => (object) [
                'kind' => 'card',
                'id' => (int) $p->id,
                'date' => $p->purchase_date,
                'description' => (string) $p->description,
                'amount_cents' => (int) $p->installment_amount_cents,
                'sort_key' => $p->purchase_date.'-'.str_pad((string) $p->id, 12, '0', STR_PAD_LEFT),
            ]);
    }

    /**
     * Histórico de tudo que já está categorizado (banco + cartão), indexado por
     * descrição exata e por estabelecimento normalizado. Uma consulta por página
     * em vez de uma por lançamento.
     *
     * @return array{exact: array<string, array<int,int>>, merchant: array<string, array<int,int>>}
     */
    private function categorizedHistory(int $userId): array
    {
        $exact = [];
        $merchant = [];

        foreach (['transactions', 'card_purchases'] as $table) {
            $rows = DB::table($table)
                ->where('user_id', $userId)
                ->whereNotNull('category_id')
                ->whereNull('deleted_at')
                ->limit(self::HISTORY_LIMIT)
                ->get(['description', 'category_id']);

            foreach ($rows as $row) {
                $categoryId = (int) $row->category_id;
                $description = (string) $row->description;

                $exactKey = mb_strtolower(trim($description));
                $exact[$exactKey][$categoryId] = ($exact[$exactKey][$categoryId] ?? 0) + 1;

                $merchantKey = mb_strtolower(MerchantNormalizer::key($description));
                if ($merchantKey !== '') {
                    $merchant[$merchantKey][$categoryId] = ($merchant[$merchantKey][$categoryId] ?? 0) + 1;
                }
            }
        }

        return ['exact' => $exact, 'merchant' => $merchant];
    }

    /**
     * Sugere uma categoria em três degraus, do sinal mais forte para o mais fraco:
     * descrição idêntica já classificada, mesmo estabelecimento (ignorando prefixo
     * de gateway e código de loja) e, por fim, as regras ativas.
     *
     * @param  array{exact: array<string, array<int,int>>, merchant: array<string, array<int,int>>}  $history
     * @param  list<array{conditions:array,actions:array}>  $rules
     * @return array{category_id: int|null, reason: string}
     */
    private function suggest(string $description, array $history, array $rules): array
    {
        $exactKey = mb_strtolower(trim($description));

        if (isset($history['exact'][$exactKey])) {
            [$categoryId, $hits] = $this->topVote($history['exact'][$exactKey]);

            return [
                'category_id' => $categoryId,
                'reason' => $hits === 1
                    ? 'você já classificou um lançamento com esta mesma descrição'
                    : "você já classificou {$hits} lançamentos com esta mesma descrição",
            ];
        }

        $merchantKey = mb_strtolower(MerchantNormalizer::key($description));

        if ($merchantKey !== '' && isset($history['merchant'][$merchantKey])) {
            [$categoryId, $hits] = $this->topVote($history['merchant'][$merchantKey]);

            return [
                'category_id' => $categoryId,
                'reason' => $hits === 1
                    ? 'você já classificou um lançamento deste mesmo estabelecimento'
                    : "você já classificou {$hits} lançamentos deste mesmo estabelecimento",
            ];
        }

        $byRule = RuleMatcher::match($description, $rules);

        if ($byRule !== null) {
            return ['category_id' => $byRule, 'reason' => 'uma regra de categorização reconheceu esta descrição'];
        }

        return ['category_id' => null, 'reason' => ''];
    }

    /**
     * @param  array<int,int>  $votes
     * @return array{0: int, 1: int}
     */
    private function topVote(array $votes): array
    {
        arsort($votes);
        $categoryId = (int) array_key_first($votes);

        return [$categoryId, (int) $votes[$categoryId]];
    }

    /** @return list<array{conditions:array,actions:array}> */
    private function activeRules(int $userId): array
    {
        return DB::table('rules')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get(['conditions', 'actions'])
            ->map(fn ($r) => ['conditions' => json_decode($r->conditions, true), 'actions' => json_decode($r->actions, true)])
            ->all();
    }

    /** @return array<int,string> */
    private function categoryNames(int $userId): array
    {
        return DB::table('categories')
            ->where('user_id', $userId)
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * Vira uma regra nova de prioridade alta (aprendizado a partir da correção
     * do usuário) — assim, na próxima importação (banco ou cartão), esse mesmo
     * estabelecimento já entra categorizado sozinho.
     */
    private function learnRule(int $userId, string $description, int $categoryId): void
    {
        $keyword = trim($description);

        if ($keyword === '') {
            return;
        }

        $existingRules = DB::table('rules')
            ->where('user_id', $userId)
            ->where('created_from', 'manual')
            ->get(['id', 'conditions']);

        foreach ($existingRules as $rule) {
            $conditions = json_decode($rule->conditions, true);
            $value = $conditions['any'][0]['value'] ?? null;

            if ($value !== null && mb_strtolower((string) $value) === mb_strtolower($keyword)) {
                DB::table('rules')->where('id', $rule->id)->update([
                    'actions' => json_encode(['set_category_id' => $categoryId]),
                    'updated_at' => now(),
                ]);

                return;
            }
        }

        DB::table('rules')->insert([
            'user_id' => $userId,
            'name' => mb_substr('Aprendido: '.$keyword, 0, self::RULE_NAME_MAX),
            'priority' => 1,
            'is_active' => true,
            'stop_on_match' => true,
            'conditions' => json_encode(['any' => [['field' => 'raw_description', 'op' => 'contains_ci', 'value' => $keyword]]]),
            'actions' => json_encode(['set_category_id' => $categoryId]),
            'applies_to' => 'all',
            'created_from' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<array{id:int,label:string}> */
    private function categoryOptions(int $userId): array
    {
        $categories = DB::table('categories')
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name']);

        $byParent = $categories->groupBy('parent_id');
        $options = [];

        $build = function ($parentId, int $depth) use (&$build, $byParent, &$options): void {
            foreach ($byParent->get($parentId, collect()) as $category) {
                // (int) para a sugestão pré-selecionada casar no === da view.
                $options[] = ['id' => (int) $category->id, 'label' => str_repeat('— ', $depth).$category->name];
                $build($category->id, $depth + 1);
            }
        };
        $build(null, 0);

        return $options;
    }
}
