<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use DateTimeImmutable;
use Grizzly\Support\FrozenClock;
use Grizzly\Support\Ulid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function test_generate_produces_26_char_crockford_base32(): void
    {
        $ulid = Ulid::generate();

        self::assertSame(26, strlen($ulid->toString()));
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid->toString());
    }

    public function test_generate_is_unique_across_calls(): void
    {
        $a = Ulid::generate();
        $b = Ulid::generate();

        self::assertNotSame($a->toString(), $b->toString());
    }

    public function test_ulids_generated_later_sort_after_earlier_ones(): void
    {
        $early = new FrozenClock(new DateTimeImmutable('2026-01-01 00:00:00.000'));
        $late = new FrozenClock(new DateTimeImmutable('2026-06-01 00:00:00.000'));

        $earlyUlid = Ulid::generate($early)->toString();
        $lateUlid = Ulid::generate($late)->toString();

        self::assertLessThan(0, strcmp($earlyUlid, $lateUlid));
    }

    public function test_from_string_accepts_valid_ulid(): void
    {
        $original = Ulid::generate();
        $parsed = Ulid::fromString($original->toString());

        self::assertSame($original->toString(), $parsed->toString());
    }

    public function test_from_string_rejects_invalid_ulid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ulid::fromString('not-a-valid-ulid');
    }

    public function test_to_string_magic_method(): void
    {
        $ulid = Ulid::generate();
        self::assertSame($ulid->toString(), (string) $ulid);
    }
}
