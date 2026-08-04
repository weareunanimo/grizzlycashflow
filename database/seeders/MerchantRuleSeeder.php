<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dicionário de ~60 regras de marcas brasileiras — docs/02-modelo-de-dados.md#4 e
 * docs/03-fluxos.md#5 (cascata de categorização, degrau "seed").
 *
 * Custo zero de IA para os casos mais comuns desde o primeiro dia. Cada entrada é uma regra
 * `stop_on_match=false` de prioridade baixa (900+): roda só depois das regras do próprio
 * usuário e antes da memória de estabelecimento não ter histórico ainda.
 *
 * Palavras-chave calibradas com os prefixos de gateway reais encontrados na fatura do PO
 * (docs/13-perfis-importadores-xp.md#25): MP*, SHOPEE*, IFD*, MERCADOLIVRE*, GNT*TEMU, etc.
 * — o casamento é por `contains_ci`, então o prefixo do gateway não atrapalha.
 */
final class MerchantRuleSeeder extends Seeder
{
    /** @var array<string, list<string>> categoria (slug de subcategoria) => palavras-chave */
    private const KEYWORDS = [
        // Transporte / Combustível
        'combustivel' => ['SHELL', 'IPIRANGA', 'PETROBRAS', 'AUTO POSTO', 'POSTO BELA'],
        'app-de-transporte' => ['UBER TRIP', '99APP', '99*', 'CABIFY'],
        'estacionamento' => ['ESTAPAR', 'ZUL ESTACIONAMENTO'],

        // Alimentação
        'delivery' => ['IFOOD', 'IFD*', 'RAPPI', 'UBER EATS', '99FOOD'],
        'mercado' => ['ANGELONI', 'CARREFOUR', 'ASSAI', 'PAO DE ACUCAR', 'SUPERMERCADO', 'ZAFFARI', 'BISTEK', 'GIASSI'],
        'restaurante' => ['MCDONALDS', 'BURGER KING', 'OUTBACK', 'HABIBS', 'SUBWAY'],
        'padaria' => ['PADARIA', 'CONFEITARIA'],

        // Saúde
        'farmacia' => ['DROGASIL', 'PANVEL', 'DROGARIA', 'PACHECO', 'RAIA DROGASIL', 'ATENA FARMACIA'],
        'consultas' => ['CLINICA', 'HOSPITAL', 'DASA', 'FLEURY', 'BIOSENS'],
        'exames' => ['LABORATORIO', 'RDSAUDE'],

        // Moradia
        'energia' => ['CELESC', 'CEMIG', 'ENEL', 'COPEL', 'LIGHT SA'],
        'agua' => ['SANEPAR', 'SABESP', 'CASAN'],
        'internet' => ['VIVO FIBRA', 'CLARO NET', 'NET VIRTUA', 'GVT'],
        'telefone' => ['VIVO', 'CLARO', 'TIM', 'OI MOVEL'],

        // Streaming e assinaturas
        'streaming' => ['NETFLIX', 'SPOTIFY', 'PRIME VIDEO', 'DISNEY PLUS', 'HBO MAX', 'DEEZER', 'YOUTUBE PREMIUM'],
        'assinaturas' => ['GOOGLE WORKSP', 'DL*GOOGLE', 'APPLE.COM', 'MICROSOFT 365', 'ANTHROPIC', 'OPENAI', 'CHATGPT'],

        // Compras / marketplace
        'compras' => [
            'MERCADOLIVRE', 'SHOPEE', 'AMAZON', 'AMAZONMKTPLC', 'ALIEXPRESS', 'SHEIN',
            'MAGALU', 'MAGAZINE LUIZA', 'AMERICANAS', 'TEMU', 'ENJOEI',
        ],
        'vestuario' => ['RENNER', 'C&A', 'RIACHUELO', 'HERING', 'ZARA', 'CEA MODAS'],
        'beleza' => ['SEPHORA', 'O BOTICARIO', 'NATURA', 'BOTICARIO'],

        // Pets
        'pets' => ['PETZ', 'COBASI', 'PETLOVE'],

        // Educação
        'educacao' => ['ESCOLA', 'FACULDADE', 'UDEMY', 'ALURA', 'CARTORIO'],

        // Impostos e taxas
        'impostos' => ['MUNICIPIO DE', 'PREFEITURA', 'DETRAN', 'RECEITA FEDERAL', 'MINISTERIO DA FAZENDA', 'IPTU', 'IPVA'],
        'taxas-bancarias' => ['TARIFA BANCARIA', 'ANUIDADE CARTAO', 'IOF'],

        // Viagens
        'viagens' => ['LATAM', 'GOL LINHAS', 'AZUL LINHAS', 'BOOKING.COM', 'AIRBNB', 'DECOLAR', 'CVC VIAGENS', 'HOTEL'],
    ];

    public function run(int $userId): void
    {
        $categoryIds = DB::table('categories')
            ->where('user_id', $userId)
            ->pluck('id', 'slug');

        $now = now();
        $priority = 900;

        foreach (self::KEYWORDS as $slug => $keywords) {
            $categoryId = $categoryIds[$slug] ?? null;
            if ($categoryId === null) {
                continue; // categoria não encontrada — não trava o seed, só pula
            }

            foreach ($keywords as $keyword) {
                DB::table('rules')->insert([
                    'user_id' => $userId,
                    'name' => "Seed: {$keyword}",
                    'priority' => $priority++,
                    'is_active' => true,
                    'stop_on_match' => true,
                    'conditions' => json_encode([
                        'any' => [
                            ['field' => 'raw_description', 'op' => 'contains_ci', 'value' => $keyword],
                        ],
                    ]),
                    'actions' => json_encode(['set_category_id' => $categoryId]),
                    'applies_to' => 'out',
                    'match_count' => 0,
                    'created_from' => 'seed',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
