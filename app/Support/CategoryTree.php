<?php

declare(strict_types=1);

namespace App\Support;

use Grizzly\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A ordem em que as categorias aparecem, em qualquer tela.
 *
 * A regra é sempre a mesma e é calculada, nunca guardada: quem tem filho vem
 * primeiro (em ordem alfabética), com os filhos logo abaixo (também em ordem),
 * e só depois as categorias soltas — as que não têm pai nem filho. Assim criar,
 * renomear ou mover uma categoria já a coloca no lugar certo, sem reordenação
 * manual e sem nenhum campo de posição para manter sincronizado.
 *
 * Ordenar por `name` no banco não serviria: em UTF-8 "Água" cairia depois de
 * "Zoológico", porque os bytes do acento vêm depois do z.
 */
final class CategoryTree
{
    /**
     * Lista achatada na ordem de exibição. Cada item traz `depth` (0 = topo),
     * `is_parent` (tem filhos) e `child_count`.
     *
     * @return list<object>
     */
    public static function ordered(int $userId): array
    {
        $categories = DB::table('categories')
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->get(['id', 'parent_id', 'name', 'kind', 'is_system'])
            ->map(function ($category) {
                $category->id = (int) $category->id;
                $category->parent_id = $category->parent_id === null ? null : (int) $category->parent_id;

                return $category;
            });

        $byParent = $categories->groupBy(fn ($c) => $c->parent_id === null ? 'root' : (string) $c->parent_id);

        $flat = [];
        self::append($flat, $byParent, 'root', 0);

        return $flat;
    }

    /**
     * Rótulo indentado para os <select> — mantém a mesma ordem da tela de
     * categorias, então a lista do dropdown não contradiz a lista da página.
     *
     * @return list<array{id:int,label:string}>
     */
    public static function options(int $userId): array
    {
        return array_map(
            fn (object $c) => ['id' => $c->id, 'label' => str_repeat('— ', $c->depth).$c->name],
            self::ordered($userId),
        );
    }

    /**
     * Quem pode ser escolhida como mãe: categoria de topo (uma filha virar mãe
     * criaria um terceiro nível) e nunca a própria categoria sendo editada.
     *
     * @return list<object>
     */
    public static function parentOptions(int $userId, ?int $excludeId = null): array
    {
        return array_values(array_filter(
            self::ordered($userId),
            fn (object $c) => $c->depth === 0 && $c->id !== $excludeId,
        ));
    }

    /** Chave de ordenação alfabética que trata acento como a letra sem acento. */
    public static function sortKey(string $name): string
    {
        return Str::normalize($name);
    }

    /**
     * @param  list<object>  $flat
     * @param  Collection<string, Collection<int, object>>  $byParent
     */
    private static function append(array &$flat, Collection $byParent, string $bucket, int $depth): void
    {
        $siblings = $byParent->get($bucket, collect());

        $withChildren = [];
        $leaves = [];

        foreach ($siblings as $category) {
            $children = $byParent->get((string) $category->id, collect());

            $category->depth = $depth;
            $category->child_count = $children->count();
            $category->is_parent = $children->isNotEmpty();

            // Regra 1 e 4: quem tem filho primeiro, categoria solta depois.
            if ($category->is_parent) {
                $withChildren[] = $category;
            } else {
                $leaves[] = $category;
            }
        }

        $alphabetically = static function (array $items): array {
            usort($items, fn ($a, $b) => strcmp(self::sortKey($a->name), self::sortKey($b->name)));

            return $items;
        };

        foreach ($alphabetically($withChildren) as $parent) {
            $flat[] = $parent;
            self::append($flat, $byParent, (string) $parent->id, $depth + 1);
        }

        foreach ($alphabetically($leaves) as $leaf) {
            $flat[] = $leaf;
        }
    }
}
