<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: disk_*_space excess argc → ArgumentCountError (#30552). */
final class DiskSpaceExcessArgc30552JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_disk_space_30552_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_disk_space_30552_jit.phpt',
            'excess_argc_disk_space_30552_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
