<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

    public function index(Request $request): View
    {
        $userId = Auth::id();
        $type = in_array($request->query('type'), ['bank', 'card'], true) ? $request->query('type') : 'all';

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

        $rows = collect();

        if ($type !== 'card') {
            $rows = $rows->concat($this->pendingTransactions($userId, $selectedAccountIds));
        }

        if ($type !== 'bank') {
            $rows = $rows->concat($this->pendingPurchases($userId, $selectedCardIds));
        }

        $rows = $rows->sortByDesc('sort_key')->values();

        $page = max(1, (int) $request->query('page', 1));
        $slice = $rows->forPage($page, self::PER_PAGE)->values();

        $pending = new LengthAwarePaginator(
            $slice,
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $suggestions = [];
        foreach ($pending as $row) {
            $suggestions[$row->kind.'-'.$row->id] = $this->suggestCategory($userId, $row->kind, $row->description, $row->id);
        }

        return view('review.index', [
            'pending' => $pending,
            'categories' => $this->categoryOptions($userId),
            'suggestions' => $suggestions,
            'type' => $type,
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
        $validated = $request->validate(['category_id' => ['required', 'integer']]);
        $categoryId = (int) $validated['category_id'];

        $table = $kind === 'bank' ? 'transactions' : 'card_purchases';
        $row = DB::table($table)->where('id', $id)->where('user_id', $userId)->first();

        if (! $row) {
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

        $status = $replicated > 0
            ? "Categoria aplicada e replicada em mais {$replicated} lançamento(s) igual(is)."
            : 'Categoria aplicada.';

        return redirect()->route('review.index', $request->query())->with('status', $status);
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

    private function suggestCategory(int $userId, string $kind, string $description, int $excludeId): ?int
    {
        $table = $kind === 'bank' ? 'transactions' : 'card_purchases';

        $row = DB::table($table)
            ->where('user_id', $userId)
            ->where('id', '!=', $excludeId)
            ->whereNotNull('category_id')
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(description) = LOWER(?)', [$description])
            ->select('category_id', DB::raw('COUNT(*) as hits'))
            ->groupBy('category_id')
            ->orderByDesc('hits')
            ->first();

        return isset($row->category_id) ? (int) $row->category_id : null;
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
