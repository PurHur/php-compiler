<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPUnit\Framework\TestCase;

/** Issue #11730 — PHP 8.4 PHP_ROUND_* mode constants registered for userland. */
final class PhpRoundModeConstantsTest extends TestCase
{
    public function testPhp84RoundModeConstantsInCoreIntByName(): void
    {
        $map = StdlibConstants::CORE_INT_BY_NAME;
        self::assertSame(5, $map['php_round_ceiling']);
        self::assertSame(6, $map['php_round_floor']);
        self::assertSame(7, $map['php_round_toward_zero']);
        self::assertSame(8, $map['php_round_away_from_zero']);
        self::assertContains('php_round_ceiling', StdlibConstants::CORE_FETCH_NAMES);
        self::assertContains('php_round_floor', StdlibConstants::CORE_FETCH_NAMES);
    }
}
