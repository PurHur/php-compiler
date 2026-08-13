<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: func_num_args/func_get_args excess argc → ArgumentCountError (#30647). */
final class FuncArgsExcessArgc30647JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_func_args_30647_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_func_args_30647_jit.phpt',
            'excess_argc_func_args_30647_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
