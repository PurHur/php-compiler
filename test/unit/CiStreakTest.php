<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Local CI streak helpers (#36401).
 */
final class CiStreakTest extends TestCase
{
    public function testRecordStartsAtOneFromEmpty(): void
    {
        require_once dirname(__DIR__, 2) . '/script/status/ci-streak-lib.php';
        $next = ci_streak_record(ci_streak_defaults(), 'abcdef1', '2026-09-04');
        $this->assertSame(1, $next['ci_green_streak_days']);
        $this->assertSame('abcdef1', $next['last_green_master_sha']);
        $this->assertSame('2026-09-04', $next['last_green_day']);
    }

    public function testRecordIncrementsConsecutiveDay(): void
    {
        require_once dirname(__DIR__, 2) . '/script/status/ci-streak-lib.php';
        $prev = ci_streak_record(ci_streak_defaults(), 'aaaaaaa', '2026-09-03');
        $next = ci_streak_record($prev, 'bbbbbbb', '2026-09-04');
        $this->assertSame(2, $next['ci_green_streak_days']);
        $this->assertSame('bbbbbbb', $next['last_green_master_sha']);
    }

    public function testRecordResetsAfterGap(): void
    {
        require_once dirname(__DIR__, 2) . '/script/status/ci-streak-lib.php';
        $prev = ci_streak_record(ci_streak_defaults(), 'aaaaaaa', '2026-09-01');
        $prev = ci_streak_record($prev, 'bbbbbbb', '2026-09-02');
        $this->assertSame(2, $prev['ci_green_streak_days']);
        $next = ci_streak_record($prev, 'ccccccc', '2026-09-04');
        $this->assertSame(1, $next['ci_green_streak_days']);
    }

    public function testRecordSameDayKeepsStreakAndRefreshesSha(): void
    {
        require_once dirname(__DIR__, 2) . '/script/status/ci-streak-lib.php';
        $prev = ci_streak_record(ci_streak_defaults(), 'aaaaaaa', '2026-09-04');
        $next = ci_streak_record($prev, 'bbbbbbb', '2026-09-04');
        $this->assertSame(1, $next['ci_green_streak_days']);
        $this->assertSame('bbbbbbb', $next['last_green_master_sha']);
    }

    public function testRecordRefusesRewind(): void
    {
        require_once dirname(__DIR__, 2) . '/script/status/ci-streak-lib.php';
        $prev = ci_streak_record(ci_streak_defaults(), 'aaaaaaa', '2026-09-04');
        $next = ci_streak_record($prev, 'bbbbbbb', '2026-09-03');
        $this->assertSame($prev, $next);
    }
}
