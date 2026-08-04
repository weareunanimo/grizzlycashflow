<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Instituições e contas reais do PO (docs/13-perfis-importadores-xp.md).
 *
 * Fechamento/vencimento do Visa Black XP são inferidos dos dados reais e marcados como
 * pendentes de confirmação — ver PROJECT.md § pendências.
 */
final class InstitutionSeeder extends Seeder
{
    public function run(int $userId): void
    {
        $now = now();

        $institutionId = DB::table('institutions')->insertGetId([
            'user_id' => $userId,
            'name' => 'Banco XP',
            'kind' => 'bank',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $checkingAccountId = DB::table('accounts')->insertGetId([
            'user_id' => $userId,
            'institution_id' => $institutionId,
            'name' => 'Banco XP',
            'type' => 'checking',
            'currency' => 'BRL',
            'opening_balance_cents' => 0,
            'opening_balance_date' => $now->toDateString(),
            'include_in_networth' => true,
            'include_in_cashflow' => true,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cardAccountId = DB::table('accounts')->insertGetId([
            'user_id' => $userId,
            'institution_id' => $institutionId,
            'name' => 'Visa Black XP',
            'type' => 'credit_card',
            'currency' => 'BRL',
            'opening_balance_cents' => 0,
            'opening_balance_date' => $now->toDateString(),
            'include_in_networth' => false, // dívida de cartão não é patrimônio
            'include_in_cashflow' => true,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('credit_cards')->insert([
            'user_id' => $userId,
            'account_id' => $cardAccountId,
            'payment_account_id' => $checkingAccountId,
            'brand' => 'visa',
            // closing_day inferido em ~17/18 dos dados reais — a confirmar (PROJECT.md)
            'closing_day' => 18,
            'due_day' => 1,
            'due_day_rule' => 'next_business_day',
            'is_virtual' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
