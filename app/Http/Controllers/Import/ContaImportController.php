<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use Grizzly\Application\Classification\RuleMatcher;
use Grizzly\Application\Ingestion\XpContaCsvParser;
use Grizzly\Domain\Classification\MerchantNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Importação do extrato da conta (perfil `xp_conta_csv`, docs/13 §1).
 *
 * Fluxo em duas etapas (preview -> commit): o arquivo fica em storage/app/tmp/ entre as
 * duas, e o commit reprocessa o arquivo do zero em vez de confiar em nada vindo do
 * formulário — evita que um preview manipulado no navegador vire dado no banco.
 */
final class ContaImportController extends Controller
{

    public function preview(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $userId = Auth::id();
        $account = DB::table('accounts')->where('id', $validated['account_id'])->where('user_id', $userId)->first();

        if (!$account) {
            abort(404);
        }

        $contents = file_get_contents($request->file('file')->getRealPath());
        $tmpPath = 'tmp/' . Str::uuid() . '.csv';
        Storage::put($tmpPath, $contents);

        $request->session()->put('import.conta', [
            'path' => $tmpPath,
            'account_id' => $account->id,
            'original_name' => $request->file('file')->getClientOriginalName(),
        ]);

        [$rows, $breaks] = $this->parseAndAnnotate($contents, (int) $account->id, $userId);

        return view('import.conta-preview', [
            'account' => $account,
            'rows' => $rows,
            'breaks' => $breaks,
            'toCreate' => count(array_filter($rows, fn (array $r) => $r['decision'] === 'novo')),
            'toSkip' => count(array_filter($rows, fn (array $r) => $r['decision'] === 'duplicata')),
        ]);
    }

    public function commit(Request $request): RedirectResponse
    {
        $userId = Auth::id();
        $pending = $request->session()->get('import.conta');

        if (!$pending) {
            return redirect()->route('import.index')->withErrors(['file' => 'Sessão de importação expirou, envie o arquivo de novo.']);
        }

        $contents = Storage::get($pending['path']);
        $account = DB::table('accounts')->where('id', $pending['account_id'])->where('user_id', $userId)->first();

        if (!$account || $contents === null) {
            return redirect()->route('import.index')->withErrors(['file' => 'Não achei mais o arquivo enviado, envie de novo.']);
        }

        [$rows] = $this->parseAndAnnotate($contents, (int) $account->id, $userId);

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

        $batchId = DB::table('import_batches')->insertGetId([
            'user_id' => $userId,
            'document_id' => $documentId,
            'importer_key' => 'xp_conta_csv',
            'target_account_id' => $account->id,
            'status' => 'committing',
            'created_at' => now(),
        ]);

        $rules = $this->activeRules($userId);
        $rendimentosCategoryId = $this->categoryIdByName($userId, 'Rendimentos');
        $transferCategoryId = $this->categoryIdByName($userId, 'Transferências');
        $created = 0;
        $skipped = 0;

        foreach ($rows as $line => $row) {
            $rowHash = hash('sha256', json_encode($row['raw']));

            $alreadyImported = DB::table('import_rows')->where('batch_id', $batchId)->where('row_hash', $rowHash)->exists();
            if ($alreadyImported) {
                continue;
            }

            if ($row['decision'] === 'duplicata') {
                DB::table('import_rows')->insert([
                    'user_id' => $userId,
                    'batch_id' => $batchId,
                    'line_no' => $line,
                    'raw' => json_encode($row['raw']),
                    'normalized' => json_encode($row['raw']),
                    'row_hash' => $rowHash,
                    'proposed_decision' => 'skip',
                    'final_decision' => 'skip',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $skipped++;
                continue;
            }

            $categoryId = RuleMatcher::match($row['description'], $rules);
            $classification = $this->classify($row['description']);
            $categorySource = $categoryId !== null ? 'rule' : null;

            // "Rendimento automático" e transferências (pagamento de fatura) já têm
            // categoria óbvia por natureza — não faz sentido pedir revisão manual.
            if (str_starts_with(mb_strtolower($row['description']), 'rendimento automático') && $rendimentosCategoryId !== null) {
                $categoryId = $rendimentosCategoryId;
                $categorySource = 'seed';
            } elseif ($classification['is_transfer'] && $transferCategoryId !== null) {
                $categoryId = $transferCategoryId;
                $categorySource = 'seed';
            }

            $needsReview = $categoryId === null && !$classification['is_transfer'];

            $importRowId = DB::table('import_rows')->insertGetId([
                'user_id' => $userId,
                'batch_id' => $batchId,
                'line_no' => $line,
                'raw' => json_encode($row['raw']),
                'normalized' => json_encode($row['raw']),
                'row_hash' => $rowHash,
                'proposed_decision' => 'create',
                'final_decision' => 'create',
                'category_id' => $categoryId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('transactions')->insert([
                'user_id' => $userId,
                'account_id' => $account->id,
                'direction' => $row['direction'],
                'amount_cents' => abs($row['amount_cents']),
                'currency' => 'BRL',
                'occurred_on' => $row['date'],
                'occurred_at' => $row['date'] . ' ' . $row['time'],
                'cash_effect_on' => $row['date'],
                'description' => $row['description'],
                'raw_description' => $row['description'],
                'category_id' => $categoryId,
                'payment_method' => $classification['payment_method'],
                'status' => 'cleared',
                'is_transfer' => $classification['is_transfer'],
                'excluded_from_analytics' => $classification['is_transfer'],
                'source' => 'csv',
                'source_document_id' => $documentId,
                'import_row_id' => $importRowId,
                'category_confidence' => $categoryId !== null ? 0.700 : null,
                'category_source' => $categorySource,
                'needs_review' => $needsReview,
                'review_reason' => $needsReview ? 'low_confidence' : null,
                'fingerprint' => $row['fingerprint'],
                'fingerprint_loose' => $row['fingerprint_loose'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created++;
        }

        DB::table('import_batches')->where('id', $batchId)->update([
            'status' => 'committed',
            'committed_at' => now(),
            'stats' => json_encode(['rows' => count($rows), 'to_create' => $created, 'to_skip' => $skipped]),
        ]);

        Storage::delete($pending['path']);
        $request->session()->forget('import.conta');

        return redirect()->route('accounts.show', $account->id)
            ->with('status', "{$created} lançamentos importados, {$skipped} já existiam e foram ignorados.");
    }

    /**
     * @return array{0: list<array<string,mixed>>, 1: list<int>}
     */
    private function parseAndAnnotate(string $contents, int $accountId, int $userId): array
    {
        $parsed = XpContaCsvParser::parse($contents);
        $breaks = XpContaCsvParser::validateBalanceChain($parsed);

        $rows = [];
        foreach ($parsed as $row) {
            $direction = $row['amount']->isNegative() ? 'out' : 'in';
            $merchantKey = MerchantNormalizer::key($row['description']);
            $fingerprint = hash('sha256', implode('|', [$accountId, $row['date'], $row['amount']->cents(), $merchantKey]));
            $fingerprintLoose = hash('sha256', implode('|', [$accountId, $row['date'], abs($row['amount']->cents())]));

            $isDuplicate = DB::table('transactions')
                ->where('account_id', $accountId)
                ->where('user_id', $userId)
                ->where('fingerprint', $fingerprint)
                ->whereNull('deleted_at')
                ->exists();

            $rows[] = [
                'raw' => [$row['date'], $row['time'], $row['description'], $row['amount']->cents(), $row['balance']->cents()],
                'date' => $row['date'],
                'time' => $row['time'],
                'description' => $row['description'],
                'amount_cents' => $row['amount']->cents(),
                'amount' => $row['amount'],
                'balance' => $row['balance'],
                'direction' => $direction,
                'fingerprint' => $fingerprint,
                'fingerprint_loose' => $fingerprintLoose,
                'decision' => $isDuplicate ? 'duplicata' : 'novo',
            ];
        }

        return [$rows, $breaks];
    }

    /** @return array{payment_method:string,is_transfer:bool} */
    private function classify(string $description): array
    {
        $d = mb_strtolower($description);

        return match (true) {
            str_contains($d, 'pagamento de fatura') => ['payment_method' => 'transfer', 'is_transfer' => true],
            str_contains($d, 'pix') => ['payment_method' => 'pix', 'is_transfer' => false],
            str_contains($d, 'ted') => ['payment_method' => 'ted', 'is_transfer' => false],
            str_contains($d, 'pagamento para') => ['payment_method' => 'boleto', 'is_transfer' => false],
            str_contains($d, 'rendimento') => ['payment_method' => 'other', 'is_transfer' => false],
            default => ['payment_method' => 'other', 'is_transfer' => false],
        };
    }

    private function categoryIdByName(int $userId, string $name): ?int
    {
        return DB::table('categories')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->whereNull('parent_id')
            ->value('id');
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
