<?php

declare(strict_types=1);

namespace Grizzly\Application\Ingestion;

use Grizzly\Support\Money;

/**
 * Perfil `xp_conta_csv` (docs/13 §1) — extrato da conta Banco XP.
 *
 * `Data;Hora;Descricao;Valor;Saldo`, ano com 2 dígitos, valores "R$ x,xx"/"-R$ x,xx".
 * O XP exporta em ordem decrescente de data — a coluna Saldo permite validar a
 * integridade do arquivo sem depender de um total (docs/13 §1.1).
 */
final class XpContaCsvParser
{
    /**
     * @return list<array{date:string,time:string,description:string,amount:Money,balance:Money}>
     */
    public static function parse(string $csv): array
    {
        $lines = self::splitLines($csv);
        array_shift($lines); // cabeçalho

        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line, ';', '"', '');
            [$date, $time, $description, $amount, $balance] = array_pad($cols, 5, '');

            $rows[] = [
                'date' => self::parseDate($date),
                'time' => trim($time),
                'description' => trim($description),
                'amount' => Money::fromBrlString($amount),
                'balance' => Money::fromBrlString($balance),
            ];
        }

        return $rows;
    }

    /** Data com ano de 2 dígitos (d/m/y) -> "Y-m-d". */
    public static function parseDate(string $raw): string
    {
        [$d, $m, $y] = array_map('intval', explode('/', trim($raw)));
        $year = $y < 70 ? 2000 + $y : 1900 + $y;

        return sprintf('%04d-%02d-%02d', $year, $m, $d);
    }

    /**
     * O XP exporta do mais recente para o mais antigo: saldo[i] == saldo[i+1] + valor[i].
     * Retorna os índices onde a cadeia quebra (vazio = arquivo íntegro, docs/13 §1.1).
     *
     * @param  list<array{amount:Money,balance:Money}>  $rows
     * @return list<int>
     */
    public static function validateBalanceChain(array $rows): array
    {
        $breaks = [];
        for ($i = 0; $i < count($rows) - 1; $i++) {
            $expected = $rows[$i + 1]['balance']->add($rows[$i]['amount']);
            if (! $expected->equals($rows[$i]['balance'])) {
                $breaks[] = $i;
            }
        }

        return $breaks;
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
