<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Fila de revisão manual (transactions.needs_review = true): nenhuma regra
 * reconheceu a categoria com confiança. Cada confirmação aqui vira uma regra
 * nova (aprendizado incremental) e é replicada em todo lançamento igual que
 * também esteja pendente — não faz sentido o usuário revisar a mesma
 * contraparte várias vezes.
 */
final class ReviewController extends Controller
{
    public function index(): View
    {
        $userId = Auth::id();

        $pending = DB::table('transactions')
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.needs_review', true)
            ->whereNull('transactions.deleted_at')
            ->orderByDesc('transactions.occurred_on')
            ->select('transactions.*', 'categories.name as category_name')
            ->paginate(20);

        $suggestions = [];
        foreach ($pending as $tx) {
            $suggestions[$tx->id] = $this->suggestCategory($userId, $tx->description, $tx->id);
        }

        return view('review.index', [
            'pending' => $pending,
            'categories' => $this->categoryOptions($userId),
            'suggestions' => $suggestions,
        ]);
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        $userId = Auth::id();
        $validated = $request->validate(['category_id' => ['required', 'integer']]);
        $categoryId = (int) $validated['category_id'];

        $tx = DB::table('transactions')->where('id', $id)->where('user_id', $userId)->first();

        if (!$tx) {
            abort(404);
        }

        DB::table('transactions')->where('id', $id)->update([
            'category_id' => $categoryId,
            'category_source' => 'user',
            'category_confidence' => 1.000,
            'needs_review' => false,
            'review_reason' => null,
            'updated_at' => now(),
        ]);

        $replicated = DB::table('transactions')
            ->where('user_id', $userId)
            ->where('id', '!=', $id)
            ->where('needs_review', true)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(description) = LOWER(?)', [$tx->description])
            ->update([
                'category_id' => $categoryId,
                'category_source' => 'user',
                'category_confidence' => 1.000,
                'needs_review' => false,
                'review_reason' => null,
                'updated_at' => now(),
            ]);

        $this->learnRule($userId, $tx->description, $categoryId);

        $status = $replicated > 0
            ? "Categoria aplicada e replicada em mais {$replicated} lançamento(s) igual(is)."
            : 'Categoria aplicada.';

        return redirect()->route('review.index')->with('status', $status);
    }

    private function suggestCategory(int $userId, string $description, int $excludeId): ?int
    {
        $row = DB::table('transactions')
            ->where('user_id', $userId)
            ->where('id', '!=', $excludeId)
            ->whereNotNull('category_id')
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(description) = LOWER(?)', [$description])
            ->select('category_id', DB::raw('COUNT(*) as hits'))
            ->groupBy('category_id')
            ->orderByDesc('hits')
            ->first();

        return $row->category_id ?? null;
    }

    /**
     * Vira uma regra nova de prioridade alta (aprendizado a partir da correção
     * do usuário) — assim, na próxima importação, esse mesmo estabelecimento
     * já entra categorizado sozinho.
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
            'name' => 'Aprendido: ' . $keyword,
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
                $options[] = ['id' => $category->id, 'label' => str_repeat('— ', $depth) . $category->name];
                $build($category->id, $depth + 1);
            }
        };
        $build(null, 0);

        return $options;
    }
}
