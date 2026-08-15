<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: error_log() ArgumentCountError wording (#31193). */
final class ErrorLogExcessArgc31193VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_error_log_31193.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_error_log_31193.phpt',
            'excess_argc_error_log_31193.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
