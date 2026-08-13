<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: filestat batch excess argc → ArgumentCountError (#30554). */
final class FilestatBatchExcessArgc30554VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_filestat_batch_30554.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_filestat_batch_30554.phpt',
            'excess_argc_filestat_batch_30554.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
