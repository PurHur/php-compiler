<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: func_num_args/func_get_args excess argc → ArgumentCountError (#30647). */
final class FuncArgsExcessArgc30647VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_func_args_30647.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_func_args_30647.phpt',
            'excess_argc_func_args_30647.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
