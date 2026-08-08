<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: user-arg TypeError/ArgumentCountError frames match Zend (#29023). */
final class UserArgTypeErrorTraceFramesVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'user_arg_typeerror_trace_frames.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/user_arg_typeerror_trace_frames.phpt',
            'user_arg_typeerror_trace_frames.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
