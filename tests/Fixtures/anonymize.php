<?php
declare(strict_types=1);

/**
 * Pseudonimizador de fixtures.
 *
 * Objetivo: manter 100% do valor de teste (estruturas, valores, datas, intervalos, prefixos de
 * gateway, totais) e remover dado pessoal de TERCEIROS.
 *
 * O que muda:
 *   - nomes de pessoas físicas  → pseudônimo estável (mesmo nome → mesmo pseudônimo sempre)
 *   - CPF/CNPJ embutido na descrição → mascarado
 *
 * O que NÃO muda (e por quê):
 *   - valores, datas, horas, saldos, parcelas → são o objeto dos testes
 *   - razão social de empresas/órgãos (CELESC, ESCOLA RIACHO DOCE, BANCO VOLKSWAGEN, MUNICIPIO DE
 *     BLUMENAU…) → não é dado pessoal e é necessária para os testes de recorrência e de normalização
 *   - marcas no campo Estabelecimento da fatura → objeto dos testes do MerchantNormalizer
 *
 * Uso: php tests/Fixtures/anonymize.php <arquivo.csv> [--in-place]
 */

/** Pessoas físicas identificadas nas amostras → pseudônimo estável. */
const PEOPLE = [
    'Felippe de Pin'                  => 'Fernando de Paula',
    'FELIPPE DE PIN'                  => 'FERNANDO DE PAULA',
    'Julia Heck Junge'                => 'Joana Haas Junqueira',
    'Daniel Felipe Souza'             => 'Douglas Ferreira Santos',
    'Juliano Wamser'                  => 'Jonas Werner',
    'Veronica Lima do Vale'           => 'Valentina Lopes do Val',
    'Alexandre de Campos Filho'       => 'Anderson de Castro Filho',
    'Glaucia Alves Correa'            => 'Gabriela Antunes Cordeiro',
    'Vitor Mateus Teixeira Alves'     => 'Victor Martins Tavares Alencar',
    'Mateus Vieira Gielow'            => 'Marcelo Viana Gerlach',
    'Mauricio Forini Scott'           => 'Marcos Fiorini Scotti',
    'Lucinete Luci Amandio'           => 'Luciene Luz Amaral',
    'Rui Celso Pereira Junior'        => 'Rubens Cesar Pinheiro Junior',
    'Raul Ribeiro da Silva Marques'   => 'Ramon Rocha da Silveira Marques',
    'Marilene Kall'                   => 'Mariana Kohl',
    'Aristheu Bessa Junior'           => 'Arnaldo Bastos Junior',
    'ANA JLIA CORRA'                  => 'ANA JULIA CORREIA',
    // vendedores pessoa física / MEI no campo Estabelecimento da fatura
    'EDSON ROGE'                      => 'EDUARDO ROQUE',
    'ROSANE GUEDES MORALES'           => 'ROSELI GOMES MORAES',
    'AIRONI CLEITON MARTIN'           => 'AILTON CLEBER MARTINS',
    'MARCELOHI'                       => 'MARCELOXY',
];

$path = $argv[1] ?? null;
$inPlace = in_array('--in-place', $argv, true);
if ($path === null || !is_file($path)) {
    fwrite(STDERR, "uso: php tests/Fixtures/anonymize.php <arquivo.csv> [--in-place]\n");
    exit(1);
}

$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "nao foi possivel ler {$path}\n");
    exit(1);
}

// 1) nomes de pessoas (as chaves mais longas primeiro, para não quebrar nomes compostos)
$people = PEOPLE;
uksort($people, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
$content = str_replace(array_keys($people), array_values($people), $content);

// 2) CPF/CNPJ embutido: sequências de 8+ dígitos (8 = raiz de CNPJ) com ou sem pontuação → mascarado,
//    preservando o comprimento para não alterar o parsing.
$content = preg_replace_callback(
    '/(?<![\d,.])(\d[\d.\s-]{6,17}\d)(?![\d,])/',
    static function (array $m): string {
        $digits = preg_replace('/\D/', '', $m[1]);
        if (strlen($digits) < 8) {   // 8 = raiz de CNPJ (padrão de nome de MEI)
            return $m[1];                        // não é documento
        }
        return preg_replace('/\d/', '*', $m[1]); // mantém formato, esconde dígitos
    },
    $content
);

if ($inPlace) {
    file_put_contents($path, $content);
    fwrite(STDERR, "anonimizado: {$path}\n");
} else {
    echo $content;
}
