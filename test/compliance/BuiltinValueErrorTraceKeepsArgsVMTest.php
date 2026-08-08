<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: builtin ValueError getTrace keeps args (#29026). */
final class BuiltinValueErrorTraceKeepsArgsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'builtin_valueerror_trace_keeps_args.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/builtin_valueerror_trace_keeps_args.phpt',
            'builtin_valueerror_trace_keeps_args.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
