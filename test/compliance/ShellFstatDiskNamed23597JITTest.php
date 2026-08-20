<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: shell_exec/fstat/fpassthru/disk_* Zend stub names + named args (#23597).
 */
final class ShellFstatDiskNamed23597JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'shell_fstat_disk_named_23597.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/shell_fstat_disk_named_23597.phpt',
            'shell_fstat_disk_named_23597.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
