<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: session_destroy / tmpfile excess argc → ArgumentCountError (#30676). */
final class SessionDestroyTmpfileExcessArgc30676VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_session_destroy_tmpfile_30676.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_session_destroy_tmpfile_30676.phpt',
            'excess_argc_session_destroy_tmpfile_30676.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
