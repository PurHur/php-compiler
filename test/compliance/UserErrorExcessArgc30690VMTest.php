<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: user_error() excess argc → ArgumentCountError at most 2 (#30690). */
final class UserErrorExcessArgc30690VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_user_error_30690.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_user_error_30690.phpt',
            'excess_argc_user_error_30690.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
