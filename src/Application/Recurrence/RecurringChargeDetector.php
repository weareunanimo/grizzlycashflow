<?php

declare(strict_types=1);

namespace Grizzly\Application\Recurrence;

use Grizzly\Domain\Classification\DescriptionCleaner;
use Grizzly\Domain\Classification\MerchantDisplayName;
use Grizzly\Domain\Classification\MerchantNormalizer;
use Grizzly\Support\Str;

/**
 * Encontra cobranças recorrentes (streaming, celular, internet, contas de consumo,
 * assinaturas de software...) olhando conta e cartão juntos.
 *
 * É só leitura e só sugestão (ADR-0010): não cria `recurrences` nem lançamentos. Uma
 * cobrança é recorrente quando aparece em pelo menos N meses seguidos (padrão 3).
 *
 * - Serviço conhecido (dicionário abaixo): basta aparecer nos meses — valor pode variar
 *   (conta de luz muda todo mês, plano de celular tem reajuste).
 * - Estabelecimento desconhecido: além dos meses, o valor tem que ser estável
 *   (±AMOUNT_TOLERANCE da mediana). Sem isso, "mercado" e "posto" virariam assinatura.
 */
final class RecurringChargeDetector
{
    public const KIND_LABELS = [
        'streaming' => 'Streaming',
        'telefonia' => 'Celular / telefone',
        'internet' => 'Internet / TV',
        'utilidades' => 'Luz, água e gás',
        'software' => 'Software e nuvem',
        'academia' => 'Academia',
        'outros' => 'Outras cobranças recorrentes',
    ];

    /** Variação máxima de valor (sobre a mediana) para um estabelecimento desconhecido. */
    private const AMOUNT_TOLERANCE = 0.20;

    /**
     * Regex (sobre a descrição normalizada em MAIÚSCULAS, sem acento) -> [nome, tipo].
     * A ordem importa: o primeiro que casar vence. Mantido pequeno e cresce com os dados
     * reais, igual a MerchantDisplayName.
     *
     * @var list<array{0:string,1:?string,2:string}>
     */
    private const KNOWN_SERVICES = [
        // Streaming de vídeo e música
        ['/NETFLIX/', 'Netflix', 'streaming'],
        ['/SPOTIFY/', 'Spotify', 'streaming'],
        ['/DISNEY/', 'Disney+', 'streaming'],
        ['/\bHBO\b|HBOMAX|\bMAX\.COM\b|WARNER ?BROS/', 'Max (HBO)', 'streaming'],
        ['/PRIME ?VIDEO|AMAZON ?PRIME|AMAZONPRIME/', 'Amazon Prime', 'streaming'],
        ['/GLOBOPLAY|GLOBO ?PLAY/', 'Globoplay', 'streaming'],
        ['/PARAMOUNT/', 'Paramount+', 'streaming'],
        ['/YOUTUBE|GOOGLE ?YOUTUBE/', 'YouTube Premium', 'streaming'],
        ['/DEEZER/', 'Deezer', 'streaming'],
        ['/CRUNCHYROLL/', 'Crunchyroll', 'streaming'],
        ['/TELECINE/', 'Telecine', 'streaming'],
        ['/\bMUBI\b/', 'Mubi', 'streaming'],
        ['/APPLE\.COM\/BILL|\bITUNES\b/', 'Apple (iCloud / Apple TV / Music)', 'streaming'],
        ['/TWITCH/', 'Twitch', 'streaming'],

        // Internet / TV por assinatura (antes de telefonia: "VIVO FIBRA" é internet)
        ['/VIVO ?FIBRA|CLARO ?NET|NET ?SERVICOS|\bNET ?CLARO\b/', null, 'internet'],
        ['/UNIFIQUE|DESKTOP|BRISANET|GIGA ?\+|GIGAMAIS|LIGGA|\bALGAR\b|\bSKY\b|ALARES|VERO ?INTERNET|\bINTERNET\b|TELECOM|\bFIBRA\b/', null, 'internet'],

        // Celular / telefone
        ['/\bVIVO\b|TELEFONICA/', 'Vivo', 'telefonia'],
        ['/\bCLARO\b/', 'Claro', 'telefonia'],
        ['/\bTIM\b|TIM ?S\.?A|TIM ?CELULAR/', 'TIM', 'telefonia'],
        ['/\bOI\b|OI ?MOVEL|OI ?FIXO/', 'Oi', 'telefonia'],

        // Contas de consumo
        ['/CELESC|CEMIG|COPEL|\bENEL\b|\bLIGHT\b|CPFL|ENERGISA|EQUATORIAL|NEOENERGIA|COELBA|CELPE|ELEKTRO|ENERGIA/', null, 'utilidades'],
        ['/SABESP|CASAN|SAMAE|SANEPAR|COPASA|CEDAE|EMBASA|COMPESA|CAGECE|\bAGUAS?\b|SANEAMENTO/', null, 'utilidades'],
        ['/COMGAS|NATURGY|ULTRAGAZ|SCGAS|\bGAS\b/', null, 'utilidades'],

        // Software, nuvem e IA
        ['/GOOGLE ?WORKSP|GOOGLE ?ONE|GOOGLE ?STORAGE|GOOGLE ?GOOGLE|\bGOOGLE\b/', 'Google (Workspace / One / Play)', 'software'],
        ['/ANTHROPIC|CLAUDE/', 'Anthropic (Claude)', 'software'],
        ['/OPENAI|CHATGPT/', 'OpenAI (ChatGPT)', 'software'],
        ['/MICROSOFT|\bMSFT\b|OFFICE ?365/', 'Microsoft 365', 'software'],
        ['/ADOBE/', 'Adobe', 'software'],
        ['/DROPBOX/', 'Dropbox', 'software'],
        ['/\bCANVA\b/', 'Canva', 'software'],
        ['/NOTION/', 'Notion', 'software'],
        ['/GITHUB/', 'GitHub', 'software'],

        // Academia / bem-estar
        ['/SMART ?FIT|GYMPASS|WELLHUB|TOTALPASS|SPORTCLUB|BLUEFIT|ACADEMIA/', null, 'academia'],
    ];

    /** Nunca é "serviço": pagamento do próprio cartão aparece todo mês na conta. */
    private const EXCLUDE = ['/PAGAMENTO DE FATURA/', '/RENDIMENTO/'];

    /**
     * `$charges` são só saídas, com `amount_cents` positivo e `channel` = 'conta'|'cartao'.
     *
     * @param  list<array{date:string,description:string,amount_cents:int,channel:string}>  $charges
     * @return list<array{
     *     key:string, name:string, kind:string, kind_label:string, known:bool,
     *     channels:list<string>, months:list<string>, streak:int,
     *     last_date:string, last_month_cents:int, avg_monthly_cents:int,
     *     min_cents:int, max_cents:int, charges:int, active:bool
     * }>
     */
    public static function detect(array $charges, int $minMonths = 3): array
    {
        $groups = [];
        $lastMonthWithData = null;

        foreach ($charges as $charge) {
            if ($charge['amount_cents'] <= 0) {
                continue; // estorno/crédito não é cobrança
            }

            $month = substr($charge['date'], 0, 7);
            $lastMonthWithData = max($lastMonthWithData ?? $month, $month);

            $cleaned = DescriptionCleaner::forDisplay($charge['description']);
            $haystack = strtoupper(Str::stripAccents($cleaned));

            if (self::matchesAny($haystack, self::EXCLUDE)) {
                continue;
            }

            [$knownName, $kind] = self::identify($haystack);
            $merchantKey = MerchantNormalizer::key($cleaned);
            $known = $kind !== null;

            // Serviço conhecido com nome fixo agrupa todas as variações de descrição
            // ("DL*GOOGLE WORKSP" e "DL*GOOGLE GOOGLE"); sem nome fixo (ex.: CELESC),
            // cada estabelecimento é um grupo.
            $key = $known && $knownName !== null ? 'svc:'.$knownName : 'mkey:'.$merchantKey;

            $groups[$key] ??= [
                'key' => $key,
                'name' => $knownName ?? MerchantDisplayName::forRawDescription($cleaned),
                'kind' => $kind ?? 'outros',
                'known' => $known,
                'by_month' => [],
                'channels' => [],
                'last_date' => $charge['date'],
                'charges' => 0,
            ];

            $group = &$groups[$key];
            $group['by_month'][$month] = ($group['by_month'][$month] ?? 0) + $charge['amount_cents'];
            $group['channels'][$charge['channel']] = true;
            $group['charges']++;
            $group['last_date'] = max($group['last_date'], $charge['date']);
            unset($group);
        }

        $found = [];
        foreach ($groups as $group) {
            ksort($group['by_month']);
            $months = array_keys($group['by_month']);
            $streak = self::longestConsecutiveRun($months);

            if ($streak < $minMonths) {
                continue;
            }

            $monthly = array_values($group['by_month']);
            if (! $group['known'] && ! self::isStable($monthly)) {
                continue;
            }

            $lastMonth = end($months);

            $found[] = [
                'key' => $group['key'],
                'name' => $group['name'],
                'kind' => $group['kind'],
                'kind_label' => self::KIND_LABELS[$group['kind']],
                'known' => $group['known'],
                'channels' => array_keys($group['channels']),
                'months' => $months,
                'streak' => $streak,
                'last_date' => $group['last_date'],
                'last_month_cents' => $group['by_month'][$lastMonth],
                'avg_monthly_cents' => (int) round(array_sum($monthly) / count($monthly)),
                'min_cents' => min($monthly),
                'max_cents' => max($monthly),
                'charges' => $group['charges'],
                // Ativa = cobrou no último mês com dados ou no anterior (fatura e extrato
                // costumam estar defasados um do outro).
                'active' => $lastMonth >= self::previousMonth((string) $lastMonthWithData),
            ];
        }

        $kindOrder = array_flip(array_keys(self::KIND_LABELS));
        usort($found, fn (array $a, array $b) => [$kindOrder[$a['kind']], $b['avg_monthly_cents']]
            <=> [$kindOrder[$b['kind']], $a['avg_monthly_cents']]);

        return $found;
    }

    /** @return array{0:?string,1:?string} [nome fixo do serviço, tipo] */
    private static function identify(string $haystack): array
    {
        foreach (self::KNOWN_SERVICES as [$pattern, $name, $kind]) {
            if (preg_match($pattern, $haystack) === 1) {
                return [$name, $kind];
            }
        }

        return [null, null];
    }

    /** @param list<string> $patterns */
    private static function matchesAny(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $months 'Y-m' ordenados */
    private static function longestConsecutiveRun(array $months): int
    {
        $best = 0;
        $run = 0;
        $previous = null;

        foreach ($months as $month) {
            $run = $previous !== null && self::previousMonth($month) === $previous ? $run + 1 : 1;
            $best = max($best, $run);
            $previous = $month;
        }

        return $best;
    }

    /** @param list<int> $values */
    private static function isStable(array $values): bool
    {
        sort($values);
        $count = count($values);
        $median = $count % 2 === 1
            ? $values[intdiv($count, 2)]
            : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;

        foreach ($values as $value) {
            if (abs($value - $median) > $median * self::AMOUNT_TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    private static function previousMonth(string $month): string
    {
        [$y, $m] = array_map('intval', explode('-', $month));

        return $m === 1 ? sprintf('%04d-12', $y - 1) : sprintf('%04d-%02d', $y, $m - 1);
    }
}
