<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: session excess argc / commit alias ACE (#30684). */
final class SessionExcessArgc30684JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_session_30684_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_session_30684_jit.phpt',
            'excess_argc_session_30684_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
