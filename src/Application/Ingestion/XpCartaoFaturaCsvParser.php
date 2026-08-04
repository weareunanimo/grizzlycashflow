<?php

declare(strict_types=1);

namespace Grizzly\Application\Ingestion;

use Grizzly\Support\Money;

/**
 * Perfil `xp_cartao_fatura_csv` (docs/13 §2) — fatura do Visa Black XP.
 *
 * `Data;Estabelecimento;Portador;Valor;Parcela`, com BOM UTF-8 antes do cabeçalho.
 * `Data` é a data da COMPRA (não do lançamento) — âncora do group_key (docs/13 §2.1).
 */
final class XpCartaoFaturaCsvParser
{
    private const INSTALLMENT_PATTERN = '/^\s*(\d{1,2})\s+de\s+(\d{1,2})\s*$/';

    /**
     * @return list<array{
     *     purchase_date:string,
     *     description:string,
     *     holder:string,
     *     amount:Money,
     *     installment_number:?int,
     *     installment_total:?int,
     *     is_payment:bool,
     * }>
     */
    public static function parse(string $csv): array
    {
        $lines = self::splitLines($csv);
        array_shift($lines); // cabeçalho

        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line, ';', '"', '');
            [$date, $description, $holder, $amount, $installment] = array_pad($cols, 5, '');
            $description = trim($description);
            [$number, $total] = self::parseInstallment($installment);

            $rows[] = [
                'purchase_date' => self::parseDate($date),
                'description' => $description,
                'holder' => trim($holder),
                'amount' => Money::fromBrlString($amount),
                'installment_number' => $number,
                'installment_total' => $total,
                'is_payment' => str_contains(mb_strtolower($description), 'pagamento de fatura'),
            ];
        }

        return $rows;
    }

    /** Data com ano de 4 dígitos (d/m/Y) -> "Y-m-d". */
    public static function parseDate(string $raw): string
    {
        [$d, $m, $y] = array_map('intval', explode('/', trim($raw)));

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /**
     * "4 de 7" -> [4,7]; "-" (à vista) -> [1,1]; "de 1" (linha de pagamento) ou
     * qualquer formato não reconhecido -> [null,null] (docs/13 §2.3).
     *
     * @return array{0:?int,1:?int}
     */
    public static function parseInstallment(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '-' || $raw === '') {
            return [1, 1];
        }

        if (preg_match(self::INSTALLMENT_PATTERN, $raw, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [null, null];
    }

    /** @return list<string> */
    private static function splitLines(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $csv = str_replace("\r\n", "\n", $csv);

        return array_values(array_filter(
            explode("\n", $csv),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }
}
