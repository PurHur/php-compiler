<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: wordwrap() null $break soft-DEP then empty ValueError on PROFILE=8.4 (#29720).
 */
final class WordwrapBreakNullJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'wordwrap_break_null_forward84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/wordwrap_break_null_forward84_jit.phpt',
            'wordwrap_break_null_forward84_jit.phpt'
        );
    }

    public function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
