<?php

declare(strict_types=1);

namespace App\Support;

use Grizzly\Domain\Classification\DescriptionCleaner;
use Grizzly\Domain\Classification\MerchantDisplayName;
use Grizzly\Domain\Classification\MerchantNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A fila de revisão: quem precisa de categoria e como esses lançamentos se
 * agrupam.
 *
 * Duas decisões moram aqui, e por isso a classe existe em vez de o controller
 * resolver tudo:
 *
 * 1. Pendente é "sem categoria", não "com a flag needs_review ligada". A flag
 *    depende de a importação ter marcado certo — e as compras importadas antes
 *    dessa marcação existir ficaram com categoria nula e flag desligada, ou
 *    seja, invisíveis na revisão. Categoria nula é o fato; a flag é só um
 *    indício.
 *
 * 2. Lançamentos do mesmo estabelecimento formam um grupo e são revisados uma
 *    vez só. "MP*MERCADOLIVRE 4471" e "MP*MERCADOLIVRE" são o mesmo lugar
 *    (MerchantNormalizer), então não faz sentido perguntar duas vezes.
 *
 * O badge do menu usa a mesma contagem de grupos que a tela mostra — se cada um
 * contasse do seu jeito, os números não fechariam.
 */
final class ReviewQueue
{
    /** Chave de agrupamento: o estabelecimento normalizado. */
    public static function groupKeyFor(string $description): string
    {
        $key = mb_strtolower(MerchantNormalizer::key($description));

        // Descrição que vira chave vazia (só pontuação, por exemplo) cai de volta
        // no próprio texto, para nunca juntar coisas diferentes num grupo "".
        return $key !== '' ? $key : mb_strtolower(trim($description));
    }

    /**
     * Um representante por grupo (o mais recente), com quantos lançamentos ele
     * responde. Ordenado do mais recente para o mais antigo.
     *
     * @param  list<int>  $accountIds  contas bancárias e cartões de benefícios
     * @param  list<int>  $creditCardIds
     * @return Collection<int,object>
     */
    public static function groups(int $userId, array $accountIds = [], array $creditCardIds = [], bool $hasFilter = false): Collection
    {
        $rows = collect();

        if (! $hasFilter || $accountIds !== []) {
            $rows = $rows->concat(self::pendingTransactions($userId, $accountIds));
        }

        if (! $hasFilter || $creditCardIds !== []) {
            $rows = $rows->concat(self::pendingPurchases($userId, $creditCardIds));
        }

        return $rows
            ->sortByDesc('sort_key')
            ->groupBy('group_key')
            ->map(function (Collection $items) {
                $representative = clone $items->first();
                $representative->group_count = $items->count();
                $representative->group_total_cents = (int) $items->sum('amount_cents');

                return $representative;
            })
            ->values()
            ->sortByDesc('sort_key')
            ->values();
    }

    /** Quantos grupos estão esperando revisão — é o número do badge. */
    public static function groupCount(int $userId): int
    {
        return self::groups($userId)->count();
    }

    /**
     * Ids pendentes equivalentes ao grupo, nas duas tabelas. O casamento é feito
     * em PHP porque a chave vem do normalizador, não de uma coluna.
     *
     * @return array{bank: list<int>, card: list<int>}
     */
    public static function equivalentPendingIds(int $userId, string $groupKey): array
    {
        $match = static function (string $table) use ($userId, $groupKey): array {
            return DB::table($table)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->where(fn (Builder $q) => self::pendingCondition($q))
                ->get(['id', 'description'])
                ->filter(fn ($row) => self::groupKeyFor((string) $row->description) === $groupKey)
                ->map(fn ($row) => (int) $row->id)
                ->values()
                ->all();
        };

        return [
            'bank' => $match('transactions'),
            'card' => $match('card_purchases'),
        ];
    }

    /** Sem categoria definida — com ou sem a flag ligada. */
    public static function pendingCondition(Builder $query): Builder
    {
        return $query->whereNull('category_id')->orWhere('needs_review', true);
    }

    /**
     * @param  list<int>  $accountIds
     * @return Collection<int,object>
     */
    private static function pendingTransactions(int $userId, array $accountIds): Collection
    {
        return DB::table('transactions')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where(fn (Builder $q) => self::pendingCondition($q))
            ->when($accountIds !== [], fn ($q) => $q->whereIn('account_id', $accountIds))
            ->orderByDesc('occurred_on')
            ->get(['id', 'occurred_on', 'description', 'direction', 'amount_cents'])
            ->map(fn ($tx) => (object) [
                'kind' => 'bank',
                'id' => (int) $tx->id,
                'date' => $tx->occurred_on,
                'description' => (string) $tx->description,
                // Mesmo nome que o extrato mostra, para a revisão não parecer outra coisa.
                'display_name' => DescriptionCleaner::forDisplay((string) $tx->description),
                'amount_cents' => $tx->direction === 'out' ? -((int) $tx->amount_cents) : (int) $tx->amount_cents,
                'group_key' => self::groupKeyFor((string) $tx->description),
                'sort_key' => $tx->occurred_on.'-'.str_pad((string) $tx->id, 12, '0', STR_PAD_LEFT),
            ]);
    }

    /**
     * @param  list<int>  $creditCardIds
     * @return Collection<int,object>
     */
    private static function pendingPurchases(int $userId, array $creditCardIds): Collection
    {
        return DB::table('card_purchases')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where(fn (Builder $q) => self::pendingCondition($q))
            ->when($creditCardIds !== [], fn ($q) => $q->whereIn('credit_card_id', $creditCardIds))
            ->orderByDesc('purchase_date')
            ->get(['id', 'purchase_date', 'description', 'installment_amount_cents'])
            ->map(fn ($p) => (object) [
                'kind' => 'card',
                'id' => (int) $p->id,
                'date' => $p->purchase_date,
                'description' => (string) $p->description,
                // Mesmo nome que a fatura mostra ("MP*MERCADOLIVRE" -> "Mercado Livre").
                'display_name' => MerchantDisplayName::forRawDescription((string) $p->description),
                'amount_cents' => (int) $p->installment_amount_cents,
                'group_key' => self::groupKeyFor((string) $p->description),
                'sort_key' => $p->purchase_date.'-'.str_pad((string) $p->id, 12, '0', STR_PAD_LEFT),
            ]);
    }
}
