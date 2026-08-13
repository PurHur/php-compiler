<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: filestat batch excess argc → ArgumentCountError (#30554). */
final class FilestatBatchExcessArgc30554JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_filestat_batch_30554_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_filestat_batch_30554_jit.phpt',
            'excess_argc_filestat_batch_30554_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
