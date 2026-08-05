<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\CategoryTree;
use Grizzly\Support\Str as GrizzlyStr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Gestão de categorias. A hierarquia tem no máximo dois níveis: uma categoria
 * de topo pode virar mãe, uma filha não — senão o relatório de fechamento
 * precisaria somar em profundidade arbitrária.
 *
 * A posição na lista nunca é escolhida à mão: CategoryTree recalcula a ordem a
 * cada exibição, então criar, renomear ou mover já deixa tudo no lugar.
 */
final class CategoryController extends Controller
{
    public function index(): View
    {
        $userId = (int) Auth::id();

        return view('categories.index', [
            'categories' => CategoryTree::ordered($userId),
            'parents' => CategoryTree::parentOptions($userId),
            'usage' => $this->usageCounts($userId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = (int) Auth::id();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'parent_id' => ['nullable', 'integer'],
            'kind' => ['nullable', 'in:expense,income,investment,transfer'],
        ], [], ['parent_id' => 'categoria-pai']);

        $parentId = $this->validParentId($userId, $validated['parent_id'] ?? null, null);
        $name = trim($validated['name']);
        $slug = $this->uniqueSlug($userId, $name, $parentId, null);

        DB::table('categories')->insert([
            'user_id' => $userId,
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            // Filha herda a natureza da mãe: uma subcategoria de despesa não é receita.
            'kind' => $parentId !== null
                ? (string) DB::table('categories')->where('id', $parentId)->value('kind')
                : ($validated['kind'] ?? 'expense'),
            'is_system' => false,
            'is_essential' => false,
            'sort_order' => 0, // a ordem é calculada por CategoryTree, não guardada
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('categories.index')->with('status', "Categoria \"{$name}\" criada.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $userId = (int) Auth::id();

        $category = DB::table('categories')->where('id', $id)->where('user_id', $userId)->first();

        if (! $category) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'parent_id' => ['nullable', 'integer'],
        ], [], ['parent_id' => 'categoria-pai']);

        $parentId = $this->validParentId($userId, $validated['parent_id'] ?? null, $id);
        $name = trim($validated['name']);

        DB::table('categories')->where('id', $id)->update([
            'name' => $name,
            'parent_id' => $parentId,
            'slug' => $this->uniqueSlug($userId, $name, $parentId, $id),
            'updated_at' => now(),
        ]);

        return redirect()->route('categories.index')->with('status', "Categoria \"{$name}\" atualizada.");
    }

    /**
     * Recusa o que quebraria a hierarquia: pai inexistente ou de outro usuário,
     * a própria categoria, um pai que já é filho (viraria o terceiro nível) e
     * dar um pai a quem já tem filhos (idem, pelo outro lado).
     */
    private function validParentId(int $userId, mixed $requested, ?int $categoryId): ?int
    {
        if ($requested === null || $requested === '' || (int) $requested === 0) {
            return null;
        }

        $parentId = (int) $requested;

        if ($parentId === $categoryId) {
            throw ValidationException::withMessages([
                'parent_id' => 'Uma categoria não pode ser a própria categoria-pai.',
            ]);
        }

        $parent = DB::table('categories')
            ->where('id', $parentId)
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->first();

        if (! $parent) {
            abort(404);
        }

        if ($parent->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent_id' => "\"{$parent->name}\" já é uma subcategoria, então não pode ser categoria-pai.",
            ]);
        }

        if ($categoryId !== null && $this->hasChildren($userId, $categoryId)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Esta categoria já tem subcategorias, então precisa continuar sem categoria-pai.',
            ]);
        }

        return $parentId;
    }

    private function hasChildren(int $userId, int $categoryId): bool
    {
        return DB::table('categories')
            ->where('user_id', $userId)
            ->where('parent_id', $categoryId)
            ->whereNull('archived_at')
            ->exists();
    }

    /** `categories` tem UNIQUE(user_id, parent_id, slug) — o sufixo evita colisão. */
    private function uniqueSlug(int $userId, string $name, ?int $parentId, ?int $ignoreId): string
    {
        $base = GrizzlyStr::slug($name);

        if ($base === '') {
            $base = 'categoria';
        }

        $slug = $base;
        $suffix = 2;

        while (
            DB::table('categories')
                ->where('user_id', $userId)
                ->where('slug', $slug)
                ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Quantos lançamentos usam cada categoria — ajuda a decidir antes de mover.
     *
     * @return array<int,int>
     */
    private function usageCounts(int $userId): array
    {
        $counts = [];

        foreach (['transactions', 'card_purchases'] as $table) {
            $rows = DB::table($table)
                ->where('user_id', $userId)
                ->whereNotNull('category_id')
                ->whereNull('deleted_at')
                ->select('category_id', DB::raw('COUNT(*) as total'))
                ->groupBy('category_id')
                ->get();

            foreach ($rows as $row) {
                $id = (int) $row->category_id;
                $counts[$id] = ($counts[$id] ?? 0) + (int) $row->total;
            }
        }

        return $counts;
    }
}
