<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * "Rendimento automático" tem categoria óbvia por natureza: Rendimentos. Não faz
 * sentido pedir revisão manual de 40 lançamentos de centavos por mês.
 *
 * A importação já classifica os novos (ContaImportController), e esta classe
 * fecha o outro lado: corrige o histórico que entrou antes dessa regra existir.
 * É idempotente — rodar de novo não muda nada.
 */
final class RendimentoCategorizer
{
    public const DESCRIPTION_PREFIX = 'Rendimento automático';

    private const CATEGORY_NAME = 'Rendimentos';

    /**
     * @return array{updated: int, category_id: int|null}
     */
    public static function backfill(int $userId): array
    {
        $categoryId = self::categoryId($userId);

        if ($categoryId === null) {
            return ['updated' => 0, 'category_id' => null];
        }

        $updated = DB::table('transactions')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('description', 'like', self::DESCRIPTION_PREFIX.'%')
            ->where(function ($q) use ($categoryId): void {
                $q->whereNull('category_id')->orWhere('category_id', '!=', $categoryId);
            })
            ->update([
                'category_id' => $categoryId,
                'category_source' => 'seed',
                'category_confidence' => 1.000,
                'needs_review' => false,
                'updated_at' => now(),
            ]);

        return ['updated' => $updated, 'category_id' => $categoryId];
    }

    /** @return array<int,array{user_id:int,updated:int}> */
    public static function backfillAllUsers(): array
    {
        $report = [];

        foreach (DB::table('users')->pluck('id') as $userId) {
            $result = self::backfill((int) $userId);
            $report[] = ['user_id' => (int) $userId, 'updated' => $result['updated']];
        }

        return $report;
    }

    public static function categoryId(int $userId): ?int
    {
        $id = DB::table('categories')
            ->where('user_id', $userId)
            ->where('name', self::CATEGORY_NAME)
            ->whereNull('parent_id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
