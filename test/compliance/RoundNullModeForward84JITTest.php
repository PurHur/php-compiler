<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: round(null $mode) DEP then ValueError on 8.4 (#29384, php-src math.c).
 *
 * @group llvm
 * @group jit
 */
final class RoundNullModeForward84JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'round_null_mode_forward84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/round_null_mode_forward84_jit.phpt',
            'round_null_mode_forward84_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
