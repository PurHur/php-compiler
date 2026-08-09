<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmRoundMode;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** round() invalid legacy mode int — ValueError on PHP 8.4 profile (#15802). */
final class VmRoundModeInvalidModeTest extends TestCase
{
    public function testInvalidLegacyModeThrowsOnPhp84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $var = new Variable();
            $var->int(99);
            $this->expectException(\ValueError::class);
            $this->expectExceptionMessage(
                'round(): Argument #3 ($mode) must be a valid rounding mode (RoundingMode::*)'
            );
            VmRoundMode::resolveRoundModeArg($var, 'round');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testValidLegacyModeUnchangedOnPhp84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $var = new Variable();
            $var->int(StdlibConstants::PHP_ROUND_HALF_UP);
            $this->assertSame(
                StdlibConstants::PHP_ROUND_HALF_UP,
                VmRoundMode::resolveRoundModeArg($var, 'round')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testInvalidLegacyModeAcceptedOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $var = new Variable();
            $var->int(99);
            $this->assertSame(99, VmRoundMode::resolveRoundModeArg($var, 'round'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Soft-null $mode → coerce 0 → ValueError on PHP 8.4 (#29384). */
    public function testNullModeDepThenValueErrorOnPhp84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $var = new Variable();
            $var->null();
            $this->expectException(\ValueError::class);
            $this->expectExceptionMessage(
                'round(): Argument #3 ($mode) must be a valid rounding mode (RoundingMode::*)'
            );
            VmRoundMode::resolveRoundModeArg($var, 'round');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
