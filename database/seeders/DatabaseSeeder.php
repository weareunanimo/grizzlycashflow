<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeder de desenvolvimento local. Em produção não existe cadastro público (ADR-0009) —
 * o usuário real é criado por `php artisan user:create` (a implementar), que também deve
 * disparar CategorySeeder/MerchantRuleSeeder/InstitutionSeeder para o novo usuário.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Felippe de Pin',
            'email' => 'dev@grizzlycashflow.test',
        ]);

        (new CategorySeeder())->run($user->id);
        (new MerchantRuleSeeder())->run($user->id);
        (new InstitutionSeeder())->run($user->id);
    }
}
