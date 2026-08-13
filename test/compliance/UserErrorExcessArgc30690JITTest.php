<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: user_error() excess argc → ArgumentCountError at most 2 (#30690). */
final class UserErrorExcessArgc30690JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_user_error_30690_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_user_error_30690_jit.phpt',
            'excess_argc_user_error_30690_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
