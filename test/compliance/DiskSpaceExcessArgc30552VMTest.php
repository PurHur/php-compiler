<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: disk_*_space excess argc → ArgumentCountError (#30552). */
final class DiskSpaceExcessArgc30552VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_disk_space_30552.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_disk_space_30552.phpt',
            'excess_argc_disk_space_30552.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
