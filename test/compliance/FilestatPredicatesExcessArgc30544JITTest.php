<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: filestat path predicates excess argc → ArgumentCountError (#30544). */
final class FilestatPredicatesExcessArgc30544JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_filestat_predicates_30544_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_filestat_predicates_30544_jit.phpt',
            'excess_argc_filestat_predicates_30544_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
