<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Exception/Error get* excess argc → ArgumentCountError (#30895). */
final class ExceptionExcessArgc30895VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_exception_methods_30895.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_exception_methods_30895.phpt',
            'excess_argc_exception_methods_30895.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
