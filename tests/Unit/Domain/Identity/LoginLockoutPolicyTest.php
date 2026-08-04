<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Identity;

use DateTimeImmutable;
use Grizzly\Domain\Identity\LoginLockoutPolicy;
use PHPUnit\Framework\TestCase;

final class LoginLockoutPolicyTest extends TestCase
{
    private LoginLockoutPolicy $policy;
    private DateTimeImmutable $lastFailure;

    protected function setUp(): void
    {
        $this->policy = new LoginLockoutPolicy();
        $this->lastFailure = new DateTimeImmutable('2026-08-04 12:00:00');
    }

    public function test_few_failures_are_never_blocked(): void
    {
        self::assertNull($this->policy->blockedUntil(4, $this->lastFailure));
    }

    public function test_five_failures_blocks_for_one_minute(): void
    {
        $until = $this->policy->blockedUntil(5, $this->lastFailure);

        self::assertSame('2026-08-04 12:01:00', $until->format('Y-m-d H:i:s'));
    }

    public function test_ten_failures_blocks_for_fifteen_minutes(): void
    {
        $until = $this->policy->blockedUntil(10, $this->lastFailure);

        self::assertSame('2026-08-04 12:15:00', $until->format('Y-m-d H:i:s'));
    }

    public function test_twenty_failures_blocks_for_one_hour(): void
    {
        $until = $this->policy->blockedUntil(20, $this->lastFailure);

        self::assertSame('2026-08-04 13:00:00', $until->format('Y-m-d H:i:s'));
    }

    public function test_is_blocked_before_cooldown_expires(): void
    {
        $now = $this->lastFailure->modify('+30 seconds');

        self::assertTrue($this->policy->isBlocked(5, $this->lastFailure, $now));
    }

    public function test_is_not_blocked_after_cooldown_expires(): void
    {
        $now = $this->lastFailure->modify('+61 seconds');

        self::assertFalse($this->policy->isBlocked(5, $this->lastFailure, $now));
    }

    public function test_is_never_blocked_with_no_recent_failures(): void
    {
        self::assertFalse($this->policy->isBlocked(0, $this->lastFailure, $this->lastFailure));
    }
}
