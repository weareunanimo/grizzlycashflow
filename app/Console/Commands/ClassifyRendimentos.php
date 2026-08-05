<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RendimentoCategorizer;
use Illuminate\Console\Command;

final class ClassifyRendimentos extends Command
{
    protected $signature = 'grizzly:classificar-rendimentos';

    protected $description = 'Classifica todo lançamento "Rendimento automático" na categoria Rendimentos (idempotente).';

    public function handle(): int
    {
        $total = 0;

        foreach (RendimentoCategorizer::backfillAllUsers() as $row) {
            $this->line("usuário {$row['user_id']}: {$row['updated']} lançamento(s) atualizado(s)");
            $total += $row['updated'];
        }

        $this->info("Total: {$total} lançamento(s) classificado(s) como Rendimentos.");

        return self::SUCCESS;
    }
}
