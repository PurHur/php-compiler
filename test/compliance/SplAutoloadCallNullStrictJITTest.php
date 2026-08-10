<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: spl_autoload_call(null) TypeError under strict_types (#29820). */
final class SplAutoloadCallNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_autoload_call_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/spl_autoload_call_null_strict_jit.phpt',
            'spl_autoload_call_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
