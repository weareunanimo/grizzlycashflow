<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Classification;

use Grizzly\Application\Classification\RuleMatcher;
use PHPUnit\Framework\TestCase;

final class RuleMatcherTest extends TestCase
{
    public function test_matches_any_condition(): void
    {
        $rules = [
            ['conditions' => ['any' => [['field' => 'raw_description', 'op' => 'contains_ci', 'value' => 'ifood']]], 'actions' => ['set_category_id' => 5]],
        ];

        self::assertSame(5, RuleMatcher::match('IFD*IFOOD SAO PAULO', $rules));
    }

    public function test_returns_null_when_nothing_matches(): void
    {
        $rules = [
            ['conditions' => ['any' => [['op' => 'contains_ci', 'value' => 'netflix']]], 'actions' => ['set_category_id' => 5]],
        ];

        self::assertNull(RuleMatcher::match('POSTO SHELL', $rules));
    }

    public function test_first_matching_rule_by_priority_order_wins(): void
    {
        $rules = [
            ['conditions' => ['any' => [['op' => 'contains_ci', 'value' => 'shell']]], 'actions' => ['set_category_id' => 1]],
            ['conditions' => ['any' => [['op' => 'contains_ci', 'value' => 'posto']]], 'actions' => ['set_category_id' => 2]],
        ];

        self::assertSame(1, RuleMatcher::match('POSTO SHELL COMBUSTIVEL', $rules));
    }

    public function test_all_conditions_must_match(): void
    {
        $rules = [
            ['conditions' => ['all' => [
                ['op' => 'contains_ci', 'value' => 'pix'],
                ['op' => 'contains_ci', 'value' => 'enviado'],
            ]], 'actions' => ['set_category_id' => 9]],
        ];

        self::assertNull(RuleMatcher::match('Pix recebido de Fulano', $rules));
        self::assertSame(9, RuleMatcher::match('Pix enviado para Fulano', $rules));
    }
}
