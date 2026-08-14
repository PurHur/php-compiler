<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SplFileInfo path accessors excess argc (#30935). */
final class SplFileInfoPathExcessArgc30935VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_splfileinfo_path_30935.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_splfileinfo_path_30935.phpt',
            'excess_argc_splfileinfo_path_30935.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
