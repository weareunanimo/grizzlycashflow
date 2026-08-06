<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Atribuir categoria a um lançamento acontece em três lugares (revisão, extrato
 * e fatura) e tem uma sutileza fácil de esquecer: `card_purchases` não tem as
 * colunas `category_source`/`category_confidence` que `transactions` tem —
 * escrever nelas do lado errado derruba a página com erro de SQL, que já
 * aconteceu em produção. Por isso a escrita mora aqui, num lugar só.
 */
final class CategoryAssignment
{
    /**
     * @param  'bank'|'card'  $kind
     * @param  list<int>  $ids
     * @return int quantos lançamentos foram atualizados
     */
    public static function apply(string $kind, int $userId, array $ids, int $categoryId): int
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return 0;
        }

        $fields = [
            'category_id' => $categoryId,
            'needs_review' => false,
            'updated_at' => now(),
        ];

        if ($kind === 'bank') {
            $fields['category_source'] = 'user';
            $fields['category_confidence'] = 1.000;
        }

        return DB::table($kind === 'bank' ? 'transactions' : 'card_purchases')
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->update($fields);
    }

    /** Categoria de outro usuário nunca pode ser aplicada. */
    public static function belongsToUser(int $userId, int $categoryId): bool
    {
        return DB::table('categories')
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->exists();
    }
}
