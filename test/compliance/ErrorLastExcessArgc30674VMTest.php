<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: error_get_last / error_clear_last excess argc → ArgumentCountError (#30674). */
final class ErrorLastExcessArgc30674VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_error_last_30674.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_error_last_30674.phpt',
            'excess_argc_error_last_30674.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
