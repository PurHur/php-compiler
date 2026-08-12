<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: filesize/filetype/file*time excess argc → ArgumentCountError (#30545). */
final class FilestatMetaExcessArgc30545JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_filestat_meta_30545_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_filestat_meta_30545_jit.phpt',
            'excess_argc_filestat_meta_30545_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
