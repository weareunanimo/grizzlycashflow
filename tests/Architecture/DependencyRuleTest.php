<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guarda a regra de dependência do ADR-0001 (monólito modular com domínio isolado) e do ADR-0005
 * (Laravel confinado à camada de infraestrutura).
 *
 * Sem este teste, a conveniência do Eloquent ou de um helper do Laravel vaza para dentro de
 * `Domain/`/`Application/` na primeira sprint apressada, e o domínio deixa de ser testável sem
 * banco/framework — o problema que a arquitetura inteira existe para evitar.
 *
 * @see docs/01-arquitetura.md#2
 */
final class DependencyRuleTest extends TestCase
{
    private const SRC_ROOT = __DIR__.'/../../src';

    /** Prefixos de `use` proibidos em Domain/ e Application/. */
    private const FORBIDDEN_IN_CORE = [
        'Illuminate\\',      // Laravel/Eloquent — ADR-0005: framework confinado a Http/Infrastructure
        'PDO',               // acesso a banco é responsabilidade de Infrastructure
        'GuzzleHttp\\',
        'App\\Http\\',
        'App\\Models\\',
    ];

    /** Funções globais que quebram a testabilidade determinística do domínio (usar Clock). */
    private const FORBIDDEN_FUNCTIONS_IN_CORE = [
        'date(', 'time(', 'strtotime(', 'microtime(', 'mktime(',
    ];

    public function test_domain_never_depends_on_framework_or_infrastructure(): void
    {
        $this->assertNoForbiddenUse('Domain');
    }

    public function test_application_never_depends_on_framework_or_infrastructure(): void
    {
        $this->assertNoForbiddenUse('Application');
    }

    public function test_domain_never_calls_date_time_functions_directly(): void
    {
        $this->assertNoForbiddenFunctionCalls('Domain');
    }

    public function test_application_never_calls_date_time_functions_directly(): void
    {
        $this->assertNoForbiddenFunctionCalls('Application');
    }

    public function test_infrastructure_never_depends_on_http(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn('Infrastructure') as $file) {
            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            if (preg_match('/^use\s+Grizzly\\\\Http\\\\/m', $content)) {
                $violations[] = $this->relativePath($file);
            }
        }

        self::assertSame(
            [],
            $violations,
            "Infrastructure/ não pode depender de Http/ (a dependência aponta para dentro):\n".implode("\n", $violations)
        );
    }

    /**
     * Se o módulo ainda não existe (ex.: Application/ na Fase 0), o teste passa vazio —
     * ele existe para travar a regra assim que o módulo nascer, não para forçar sua existência.
     */
    private function assertNoForbiddenUse(string $module): void
    {
        $violations = [];

        foreach ($this->phpFilesIn($module) as $file) {
            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            foreach (self::FORBIDDEN_IN_CORE as $forbidden) {
                $pattern = '/^use\s+'.preg_quote($forbidden, '/').'/m';
                if (preg_match($pattern, $content) || str_contains($content, '\\'.$forbidden)) {
                    $violations[] = $this->relativePath($file)." → \"{$forbidden}\"";
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "{$module}/ deve ser PHP puro (ADR-0001/ADR-0005). Violações encontradas:\n".implode("\n", $violations)
        );
    }

    private function assertNoForbiddenFunctionCalls(string $module): void
    {
        $violations = [];

        foreach ($this->phpFilesIn($module) as $file) {
            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            foreach (self::FORBIDDEN_FUNCTIONS_IN_CORE as $fn) {
                // ignora chamadas qualificadas tipo Clock::... e comentários simples de linha
                $lines = explode("\n", $content);
                foreach ($lines as $lineNo => $line) {
                    $trimmed = ltrim($line);
                    if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                        continue;
                    }
                    if (str_contains($line, $fn) && ! str_contains($line, '::'.$fn) && ! str_contains($line, '->'.$fn)) {
                        $violations[] = $this->relativePath($file).':'.($lineNo + 1)." → \"{$fn}\" (use Clock em vez disso)";
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "{$module}/ nunca deve chamar date/time diretamente — injete Grizzly\\Support\\Clock:\n".implode("\n", $violations)
        );
    }

    /** @return list<SplFileInfo> */
    private function phpFilesIn(string $module): array
    {
        $dir = self::SRC_ROOT.'/'.$module;

        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function relativePath(SplFileInfo $file): string
    {
        return str_replace(realpath(self::SRC_ROOT.'/..').'/', '', $file->getPathname());
    }
}
