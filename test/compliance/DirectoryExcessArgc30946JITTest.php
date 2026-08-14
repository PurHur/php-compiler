<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: Directory::{read,rewind,close} excess argc (#30946). */
final class DirectoryExcessArgc30946JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_directory_30946_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_directory_30946_jit.phpt',
            'excess_argc_directory_30946_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
