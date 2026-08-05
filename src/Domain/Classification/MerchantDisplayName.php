<?php

declare(strict_types=1);

namespace Grizzly\Domain\Classification;

/**
 * Nome de estabelecimento amigável para exibição — "MP*MERCADOLIVRE" vira "Mercado Livre",
 * "IFD*SANTA LARICA PASTELAR" vira "iFood - Santa Larica Pastelar". Não é a chave de dedup
 * (essa é `MerchantNormalizer::key()`), é só cosmético.
 *
 * Dicionário pequeno e deliberadamente incompleto: cresce conforme aparecem estabelecimentos
 * novos nos dados reais, igual às regras de categorização (ADR de aprendizado incremental).
 */
final class MerchantDisplayName
{
    /**
     * Prefixos cujo próprio nome já é a marca reconhecível. O 3º valor decide se o que vem
     * depois do "*" ainda ajuda (ex.: iFood -> nome do restaurante) ou é só ruído interno
     * de marketplace (Shopee/Mercado Livre/Amazon/LATAM sempre mostram só a marca).
     *
     * @var list<array{0:string,1:string,2:bool}>
     */
    private const PREFIX_RULES = [
        ['IFD', 'iFood', true],
        ['SHOPEE', 'Shopee', false],
        ['MERCADOLIVRE', 'Mercado Livre', false],
        ['AMAZONMKTPLC', 'Amazon', false],
        ['LATAM AIR', 'LATAM Airlines', false],
    ];

    /** Restante do nome que não agrega nada (código genérico, não é o restaurante/loja real). */
    private const MEANINGLESS_REMAINDER = ['BR', ''];

    /** Chave normalizada (após MerchantNormalizer::key, sem prefixo de gateway) -> nome amigável. */
    private const KNOWN_KEYS = [
        'MERCADOLIVRE' => 'Mercado Livre',
        'AMAZON BR' => 'Amazon',
        'TEMU.COM' => 'Temu',
        'TEMU' => 'Temu',
        'ALIEXPRESS' => 'AliExpress',
        'SPOTIFY' => 'Spotify',
        'NETFLIX' => 'Netflix',
        'GOOGLE WORKSP' => 'Google Workspace',
        'GOOGLE GOOGLE' => 'Google',
        'APPLE.COM/BILL' => 'Apple',
        'BOOKING.COM' => 'Booking.com',
        'HOTEL AT BOOKING.COM' => 'Booking.com',
        'ANTHROPIC' => 'Anthropic (Claude)',
        'BANCO XP' => 'Banco XP',
        'BANCO VOLKSWAGEN' => 'Banco Volkswagen',
    ];

    public static function forRawDescription(string $rawDescription): string
    {
        $upper = strtoupper(trim($rawDescription));

        foreach (self::PREFIX_RULES as [$prefix, $brand, $showDetail]) {
            if (! str_starts_with($upper, $prefix)) {
                continue;
            }

            if (! $showDetail) {
                return $brand;
            }

            $rest = MerchantNormalizer::key(ltrim(substr($upper, strlen($prefix)), " \t*"));

            return $rest !== '' && ! in_array($rest, self::MEANINGLESS_REMAINDER, true)
                ? $brand.' - '.self::titleCase($rest)
                : $brand;
        }

        $key = MerchantNormalizer::key($rawDescription);

        return self::KNOWN_KEYS[$key] ?? self::titleCase($key);
    }

    private static function titleCase(string $value): string
    {
        return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8');
    }
}
