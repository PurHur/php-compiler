<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: Exception/Error get* excess argc → ArgumentCountError (#30895). */
final class ExceptionExcessArgc30895JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_exception_methods_30895_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_exception_methods_30895_jit.phpt',
            'excess_argc_exception_methods_30895_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
