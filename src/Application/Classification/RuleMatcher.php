<?php

declare(strict_types=1);

namespace Grizzly\Application\Classification;

/**
 * Avalia as regras de categorização (tabela `rules`) contra uma descrição, na ordem
 * de prioridade em que foram fornecidas. Formato de `conditions`/`actions` — docs/09.
 */
final class RuleMatcher
{
    /**
     * @param list<array{conditions: array, actions: array}> $rulesByPriorityDesc
     */
    public static function match(string $description, array $rulesByPriorityDesc): ?int
    {
        $haystack = mb_strtolower($description);

        foreach ($rulesByPriorityDesc as $rule) {
            if (self::conditionsMatch($rule['conditions'], $haystack)) {
                $categoryId = $rule['actions']['set_category_id'] ?? null;

                return $categoryId !== null ? (int) $categoryId : null;
            }
        }

        return null;
    }

    private static function conditionsMatch(array $conditions, string $haystack): bool
    {
        if (isset($conditions['any'])) {
            foreach ($conditions['any'] as $condition) {
                if (self::conditionMatches($condition, $haystack)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($conditions['all'])) {
            foreach ($conditions['all'] as $condition) {
                if (!self::conditionMatches($condition, $haystack)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private static function conditionMatches(array $condition, string $haystack): bool
    {
        return match ($condition['op'] ?? 'contains_ci') {
            'contains_ci' => str_contains($haystack, mb_strtolower((string) $condition['value'])),
            default => false,
        };
    }
}
