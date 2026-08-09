<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: nested function Exception::getTrace() keeps args (#29207). */
final class NestedFunctionExceptionGetTraceArgsJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'nested_function_exception_gettrace_args.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/nested_function_exception_gettrace_args.phpt',
            'nested_function_exception_gettrace_args.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
