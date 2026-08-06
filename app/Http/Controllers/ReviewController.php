<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\CategoryAssignment;
use App\Support\CategoryTree;
use App\Support\RendimentoCategorizer;
use App\Support\ReviewQueue;
use Grizzly\Application\Classification\RuleMatcher;
use Grizzly\Domain\Classification\MerchantNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        // Contas bancárias de um lado; cartões (crédito e benefícios) do outro.
        $accounts = DB::table('accounts')
            ->where('user_id', $userId)
            ->whereNotIn('type', ['credit_card', 'voucher'])
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get(['id', 'name']);

        $cards = $this->cardFilterOptions($userId);

        $selectedAccountIds = $this->validAccountIds($accounts, (array) $request->query('accounts', []));
        $selectedCardKeys = $this->validCardKeys($cards, (array) $request->query('cards', []));

        // Cartão de benefícios não tem fatura: os lançamentos dele estão em
        // `transactions`, então entram pelo filtro de conta, mas o usuário o
        // seleciona como cartão. Por isso a chave carrega o tipo (c<id>/v<id>).
        $creditCardIds = [];
        $voucherAccountIds = [];

        foreach ($selectedCardKeys as $key) {
            if (str_starts_with($key, 'v')) {
                $voucherAccountIds[] = (int) substr($key, 1);
            } else {
                $creditCardIds[] = (int) substr($key, 1);
            }
        }

        $hasFilter = $selectedAccountIds !== [] || $selectedCardKeys !== [];
        $transactionAccountIds = array_merge($selectedAccountIds, $voucherAccountIds);

        // Sem filtro, mostra tudo. Com filtro, cada lado só entra se foi escolhido —
        // antes o lado sem seleção passava inteiro, e conta e cartão devolviam o mesmo.
        // A fila devolve um representante por estabelecimento, não um card por lançamento.
        $rows = ReviewQueue::groups($userId, $transactionAccountIds, $creditCardIds, $hasFilter);

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
            'selectedCardKeys' => $selectedCardKeys,
        ]);
    }

    /**
     * Cartões de crédito e de benefícios na mesma lista, cada um com uma chave
     * que diz de onde vêm os lançamentos: c<credit_card_id> ou v<account_id>.
     *
     * @return Collection<int,object>
     */
    private function cardFilterOptions(int $userId): Collection
    {
        $credit = DB::table('credit_cards')
            ->join('accounts', 'accounts.id', '=', 'credit_cards.account_id')
            ->where('credit_cards.user_id', $userId)
            ->whereNull('accounts.archived_at')
            ->orderBy('credit_cards.id')
            ->get(['credit_cards.id', 'accounts.name as account_name'])
            ->map(fn ($card) => (object) ['key' => 'c'.$card->id, 'name' => (string) $card->account_name]);

        $vouchers = DB::table('accounts')
            ->where('user_id', $userId)
            ->where('type', 'voucher')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($account) => (object) ['key' => 'v'.$account->id, 'name' => (string) $account->name]);

        return $credit->concat($vouchers)->values();
    }

    /**
     * Só aceita ids que o usuário realmente possui — um id colado na URL não
     * pode virar uma consulta pelos dados de outra pessoa.
     *
     * @param  Collection<int,object>  $accounts
     * @param  array<mixed>  $requested
     * @return list<int>
     */
    private function validAccountIds(Collection $accounts, array $requested): array
    {
        $owned = $accounts->map(fn ($a) => (int) $a->id)->all();

        return array_values(array_intersect(array_map('intval', $requested), $owned));
    }

    /**
     * @param  Collection<int,object>  $cards
     * @param  array<mixed>  $requested
     * @return list<string>
     */
    private function validCardKeys(Collection $cards, array $requested): array
    {
        $owned = $cards->pluck('key')->all();

        return array_values(array_intersect(array_map('strval', $requested), $owned));
    }

    public function store(Request $request, string $kind, int $id): RedirectResponse
    {
        if (! in_array($kind, ['bank', 'card'], true)) {
            abort(404);
        }

        $userId = Auth::id();

        // Criar categoria é assunto da tela Categorias, que trata hierarquia e ordem.
        $validated = $request->validate([
            'category_id' => ['required', 'integer'],
        ]);

        $table = $kind === 'bank' ? 'transactions' : 'card_purchases';
        $row = DB::table($table)->where('id', $id)->where('user_id', $userId)->first();

        if (! $row) {
            abort(404);
        }

        $categoryId = (int) $validated['category_id'];

        if (! CategoryAssignment::belongsToUser($userId, $categoryId)) {
            abort(404);
        }

        // A decisão vale para o grupo inteiro (mesmo estabelecimento), nas duas
        // tabelas — é o que evita revisar a mesma contraparte dezenas de vezes.
        $groupKey = ReviewQueue::groupKeyFor((string) $row->description);
        $equivalent = ReviewQueue::equivalentPendingIds($userId, $groupKey);

        $equivalent[$kind === 'bank' ? 'bank' : 'card'][] = $id;

        $applied = CategoryAssignment::apply('bank', $userId, $equivalent['bank'], $categoryId)
            + CategoryAssignment::apply('card', $userId, $equivalent['card'], $categoryId);

        $this->learnRule($userId, $row->description, $categoryId);

        $name = DB::table('categories')->where('id', $categoryId)->value('name');

        $status = $applied > 1
            ? "\"{$name}\" aplicada em {$applied} lançamentos do mesmo estabelecimento."
            : "\"{$name}\" aplicada.";

        return redirect()->route('review.index', $request->query())->with('status', $status);
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

    /**
     * Mesma ordem da tela de Categorias — o dropdown não pode contradizer a lista.
     *
     * @return list<array{id:int,label:string}>
     */
    private function categoryOptions(int $userId): array
    {
        return CategoryTree::options($userId);
    }
}
