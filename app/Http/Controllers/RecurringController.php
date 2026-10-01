<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Grizzly\Application\Recurrence\RecurringChargeDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "O que eu pago todo mês?" — streaming, celular, internet, contas de consumo e
 * demais cobranças que se repetem, lendo extrato da conta e fatura do cartão juntos.
 *
 * Só leitura: a detecção roda a cada visita sobre o que já foi importado e não grava
 * nada (recorrência é agrupador, nunca gerador — ADR-0010).
 */
final class RecurringController extends Controller
{
    /** Quanto histórico olhar. Uma assinatura anual não aparece aqui, só mensais. */
    private const LOOKBACK_MONTHS = 12;

    public function index(Request $request): View
    {
        $userId = Auth::id();
        $minMonths = max(2, min(12, (int) $request->query('meses', 3)));
        $since = now()->subMonthsNoOverflow(self::LOOKBACK_MONTHS)->startOfMonth()->toDateString();

        $bank = DB::table('transactions')
            ->where('user_id', $userId)
            ->where('direction', 'out')
            ->where('is_transfer', false)
            ->whereNull('deleted_at')
            ->where('occurred_on', '>=', $since)
            ->get(['occurred_on', 'description', 'raw_description', 'amount_cents'])
            ->map(fn ($t) => [
                'date' => (string) $t->occurred_on,
                'description' => (string) ($t->raw_description ?: $t->description),
                'amount_cents' => (int) $t->amount_cents,
                'channel' => 'conta',
            ]);

        // Assinatura no cartão é sempre à vista: compra parcelada se repete todo mês
        // mas não é serviço, e já aparece na projeção de faturas.
        $card = DB::table('card_purchases')
            ->where('user_id', $userId)
            ->where('installments_total', 1)
            ->whereNull('deleted_at')
            ->whereRaw('COALESCE(purchase_date, first_reference_month) >= ?', [$since])
            ->get(['purchase_date', 'first_reference_month', 'description', 'raw_description', 'installment_amount_cents'])
            ->map(fn ($p) => [
                'date' => (string) ($p->purchase_date ?: $p->first_reference_month),
                'description' => (string) ($p->raw_description ?: $p->description),
                'amount_cents' => (int) $p->installment_amount_cents,
                'channel' => 'cartao',
            ]);

        $items = RecurringChargeDetector::detect($bank->concat($card)->values()->all(), $minMonths);

        $services = array_values(array_filter($items, fn (array $i) => $i['known']));
        $others = array_values(array_filter($items, fn (array $i) => ! $i['known']));

        $byKind = [];
        foreach ($services as $item) {
            $byKind[$item['kind']][] = $item;
        }

        $activeMonthly = fn (array $list) => array_sum(array_map(
            fn (array $i) => $i['avg_monthly_cents'],
            array_filter($list, fn (array $i) => $i['active']),
        ));

        return view('recurring.index', [
            'minMonths' => $minMonths,
            'byKind' => $byKind,
            'others' => $others,
            'kindLabels' => RecurringChargeDetector::KIND_LABELS,
            'servicesMonthlyCents' => $activeMonthly($services),
            'othersMonthlyCents' => $activeMonthly($others),
            'servicesCount' => count(array_filter($services, fn (array $i) => $i['active'])),
        ]);
    }
}
