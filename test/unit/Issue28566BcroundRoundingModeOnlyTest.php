<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmRoundMode;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * #28566 — bcround() rejects int $mode; RoundingMode only (php-src bcmath.stub.php).
 */
final class Issue28566BcroundRoundingModeOnlyTest extends TestCase
{
    public function testResolveRoundingModeOnlyRejectsInt(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsRoundingModeEnum()) {
                $this->markTestSkipped('RoundingMode requires PHP_COMPILER_PROFILE≥8.4 (#28566)');
            }
            $var = new Variable();
            $var->int(1);
            $this->expectException(\TypeError::class);
            $this->expectExceptionMessage(
                'bcround(): Argument #3 ($mode) must be of type RoundingMode, int given'
            );
            VmRoundMode::resolveRoundingModeOnlyArg($var, 'bcround');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testResolveRoundingModeOnlyRejectsNull(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsRoundingModeEnum()) {
                $this->markTestSkipped('RoundingMode requires PHP_COMPILER_PROFILE≥8.4 (#28566)');
            }
            $var = new Variable(Variable::TYPE_NULL);
            $this->expectException(\TypeError::class);
            $this->expectExceptionMessage(
                'bcround(): Argument #3 ($mode) must be of type RoundingMode, null given'
            );
            VmRoundMode::resolveRoundingModeOnlyArg($var, 'bcround');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
