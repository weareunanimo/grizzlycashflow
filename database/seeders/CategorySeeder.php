<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Árvore de categorias em 2 níveis — docs/02-modelo-de-dados.md#4.
 *
 * `is_system = true`: não pode ser deletada pelo usuário, só arquivada — evita que uma
 * categoria referenciada por milhares de transações históricas suma da noite pro dia.
 */
final class CategorySeeder extends Seeder
{
    /** @var array<string, array{kind: string, essential?: bool, children?: list<string>}> */
    private const TREE = [
        'Alimentação' => ['kind' => 'expense', 'children' => ['Mercado', 'Restaurante', 'Delivery', 'Padaria']],
        'Transporte' => ['kind' => 'expense', 'children' => ['Combustível', 'App de Transporte', 'Estacionamento', 'Pedágio', 'Manutenção']],
        'Moradia' => ['kind' => 'expense', 'essential' => true, 'children' => ['Aluguel', 'Condomínio', 'Energia', 'Água', 'Internet', 'Telefone', 'Gás']],
        'Saúde' => ['kind' => 'expense', 'children' => ['Farmácia', 'Consultas', 'Exames', 'Plano de Saúde']],
        'Lazer' => ['kind' => 'expense'],
        'Streaming' => ['kind' => 'expense'],
        'Assinaturas' => ['kind' => 'expense'],
        'Educação' => ['kind' => 'expense', 'essential' => true],
        'Investimentos' => ['kind' => 'investment'],
        'Pets' => ['kind' => 'expense'],
        'Presentes' => ['kind' => 'expense'],
        'Impostos' => ['kind' => 'expense'],
        'Viagens' => ['kind' => 'expense'],
        'Compras' => ['kind' => 'expense'],
        'Vestuário' => ['kind' => 'expense'],
        'Beleza' => ['kind' => 'expense'],
        'Doações' => ['kind' => 'expense'],
        'Taxas Bancárias' => ['kind' => 'expense'],
        'Salário' => ['kind' => 'income'],
        'Rendimentos' => ['kind' => 'income'],
        'Reembolsos' => ['kind' => 'income'],
        'Transferências' => ['kind' => 'transfer'],
        'Outros' => ['kind' => 'expense'],
    ];

    public function run(int $userId): void
    {
        $now = now();
        $order = 0;

        foreach (self::TREE as $name => $spec) {
            $parentId = DB::table('categories')->insertGetId([
                'user_id' => $userId,
                'parent_id' => null,
                'name' => $name,
                'slug' => Str::slug($name),
                'kind' => $spec['kind'],
                'is_system' => true,
                'is_essential' => $spec['essential'] ?? false,
                'sort_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $childOrder = 0;
            foreach ($spec['children'] ?? [] as $childName) {
                DB::table('categories')->insert([
                    'user_id' => $userId,
                    'parent_id' => $parentId,
                    'name' => $childName,
                    'slug' => Str::slug($childName),
                    'kind' => $spec['kind'],
                    'is_system' => true,
                    'is_essential' => $spec['essential'] ?? false,
                    'sort_order' => $childOrder++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
