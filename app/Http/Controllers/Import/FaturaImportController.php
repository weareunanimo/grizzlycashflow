<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Grizzly\Application\Card\GroupKey;
use Grizzly\Application\Classification\RuleMatcher;
use Grizzly\Application\Ingestion\XpCartaoFaturaCsvParser;
use Grizzly\Domain\Classification\MerchantNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Importação da fatura do cartão (perfil `xp_cartao_fatura_csv`, docs/13 §2).
 *
 * Cada linha vira uma `card_purchases` (na primeira vez que o group_key aparece) +
 * a projeção completa de `card_installments` (invariante I10 — nunca duplica entre
 * faturas sucessivas). A linha "Pagamento de fatura" é ignorada aqui: já entra pelo
 * extrato da conta (docs/13 §2.4).
 */
final class FaturaImportController extends Controller
{

    public function preview(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'credit_card_id' => ['required', 'integer'],
            'reference_month' => ['required', 'date_format:Y-m'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $userId = Auth::id();
        $card = DB::table('credit_cards')->where('id', $validated['credit_card_id'])->where('user_id', $userId)->first();

        if (!$card) {
            abort(404);
        }

        $contents = file_get_contents($request->file('file')->getRealPath());
        $tmpPath = 'tmp/' . Str::uuid() . '.csv';
        Storage::put($tmpPath, $contents);

        $request->session()->put('import.fatura', [
            'path' => $tmpPath,
            'credit_card_id' => $card->id,
            'reference_month' => $validated['reference_month'] . '-01',
            'original_name' => $request->file('file')->getClientOriginalName(),
        ]);

        $rows = $this->parseAndAnnotate($contents, (int) $card->id, $userId, $validated['reference_month'] . '-01');

        return view('import.fatura-preview', [
            'card' => $card,
            'rows' => $rows,
            'referenceMonth' => $validated['reference_month'],
            'newPurchases' => count(array_filter($rows, fn (array $r) => $r['decision'] === 'nova_compra')),
            'confirmed' => count(array_filter($rows, fn (array $r) => $r['decision'] === 'confirma_projecao')),
            'ignored' => count(array_filter($rows, fn (array $r) => $r['decision'] === 'ignorado')),
        ]);
    }

    public function commit(Request $request): RedirectResponse
    {
        $userId = Auth::id();
        $pending = $request->session()->get('import.fatura');

        if (!$pending) {
            return redirect()->route('import.index')->withErrors(['file' => 'Sessão de importação expirou, envie o arquivo de novo.']);
        }

        $contents = Storage::get($pending['path']);
        $card = DB::table('credit_cards')->where('id', $pending['credit_card_id'])->where('user_id', $userId)->first();

        if (!$card || $contents === null) {
            return redirect()->route('import.index')->withErrors(['file' => 'Não achei mais o arquivo enviado, envie de novo.']);
        }

        $referenceMonth = $pending['reference_month'];
        $rows = $this->parseAndAnnotate($contents, (int) $card->id, $userId, $referenceMonth);

        $sha256 = hash('sha256', $contents);
        $existingDoc = DB::table('documents')->where('user_id', $userId)->where('sha256', $sha256)->first();

        $documentId = $existingDoc->id ?? null;
        if ($documentId === null) {
            $storagePath = 'uploads/' . $sha256 . '.csv';
            Storage::put($storagePath, $contents);

            $documentId = DB::table('documents')->insertGetId([
                'user_id' => $userId,
                'kind' => 'csv',
                'channel' => 'web',
                'original_name' => $pending['original_name'],
                'mime' => 'text/csv',
                'size_bytes' => strlen($contents),
                'sha256' => $sha256,
                'storage_path' => $storagePath,
                'status' => 'imported',
                'uploaded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $rules = $this->activeRules($userId);
        $created = 0;
        $confirmed = 0;

        foreach ($rows as $row) {
            if ($row['decision'] === 'ignorado') {
                continue;
            }

            $existing = DB::table('card_purchases')
                ->where('user_id', $userId)
                ->where('credit_card_id', $card->id)
                ->where('group_key', $row['group_key'])
                ->first();

            if ($existing) {
                DB::table('card_installments')
                    ->where('purchase_id', $existing->id)
                    ->where('number', $row['installment_number'])
                    ->update([
                        'status' => 'billed',
                        'is_reconstructed' => false,
                        'amount_cents' => $row['amount_cents'],
                        'updated_at' => now(),
                    ]);
                $confirmed++;
                continue;
            }

            $categoryId = RuleMatcher::match($row['description'], $rules);

            $purchaseId = DB::table('card_purchases')->insertGetId([
                'user_id' => $userId,
                'credit_card_id' => $card->id,
                'category_id' => $categoryId,
                'description' => $row['description'],
                'raw_description' => $row['description'],
                'purchase_date' => $row['purchase_date'],
                'installments_total' => $row['installment_total'],
                'installment_amount_cents' => $row['amount_cents'],
                'total_amount_cents' => $row['amount_cents'] * $row['installment_total'],
                'first_reference_month' => $row['first_reference_month'],
                'currency' => 'BRL',
                'status' => $row['amount_cents'] < 0 ? 'refunded' : 'active',
                'is_installment_plan' => $row['installment_total'] > 1,
                'group_key' => $row['group_key'],
                'detection_confidence' => 1.000,
                'needs_review' => $categoryId === null,
                'source' => 'csv',
                'source_document_id' => $documentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            for ($k = 1; $k <= $row['installment_total']; $k++) {
                $installmentMonth = Carbon::parse($row['first_reference_month'])->addMonthsNoOverflow($k - 1)->format('Y-m-01');

                DB::table('card_installments')->insert([
                    'user_id' => $userId,
                    'purchase_id' => $purchaseId,
                    'credit_card_id' => $card->id,
                    'number' => $k,
                    'installments_total' => $row['installment_total'],
                    'amount_cents' => $row['amount_cents'],
                    'reference_month' => $installmentMonth,
                    'status' => $k <= $row['installment_number'] ? 'billed' : 'projected',
                    'is_reconstructed' => $k !== $row['installment_number'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $created++;
        }

        Storage::delete($pending['path']);
        $request->session()->forget('import.fatura');

        return redirect()->route('cards.show', $card->id)
            ->with('status', "{$created} compras novas, {$confirmed} parcelas projetadas confirmadas nesta fatura.");
    }

    /** @return list<array<string,mixed>> */
    private function parseAndAnnotate(string $contents, int $creditCardId, int $userId, string $referenceMonth): array
    {
        $parsed = XpCartaoFaturaCsvParser::parse($contents);
        $rows = [];

        foreach ($parsed as $row) {
            if ($row['is_payment']) {
                $rows[] = array_merge($row, ['decision' => 'ignorado', 'amount_cents' => $row['amount']->cents()]);
                continue;
            }

            $merchantKey = MerchantNormalizer::key($row['description']);
            $amountCents = $row['amount']->cents();
            $groupKey = GroupKey::compute(
                $creditCardId,
                $merchantKey,
                abs($amountCents),
                (int) $row['installment_total'],
                $row['purchase_date'],
            );

            $firstReferenceMonth = Carbon::parse($referenceMonth)
                ->subMonthsNoOverflow($row['installment_number'] - 1)
                ->format('Y-m-01');

            $exists = DB::table('card_purchases')
                ->where('user_id', $userId)
                ->where('credit_card_id', $creditCardId)
                ->where('group_key', $groupKey)
                ->exists();

            $rows[] = array_merge($row, [
                'amount_cents' => $amountCents,
                'group_key' => $groupKey,
                'first_reference_month' => $firstReferenceMonth,
                'decision' => $exists ? 'confirma_projecao' : 'nova_compra',
            ]);
        }

        return $rows;
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
}
