<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: pow(null) silent coerce; fpow still soft-null DEP (#29322, re-#20951). */
final class PowNullSilentJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pow_null_silent_forward84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/pow_null_silent_forward84_jit.phpt',
            'pow_null_silent_forward84_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
