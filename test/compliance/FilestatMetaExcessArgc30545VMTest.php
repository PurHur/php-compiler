<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: filesize/filetype/file*time excess argc → ArgumentCountError (#30545). */
final class FilestatMetaExcessArgc30545VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_filestat_meta_30545.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_filestat_meta_30545.phpt',
            'excess_argc_filestat_meta_30545.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
