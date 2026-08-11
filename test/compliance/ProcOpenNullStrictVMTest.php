<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: proc_open(null) TypeError under strict_types (#30247). */
final class ProcOpenNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'proc_open_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/proc_open_null_strict.phpt',
            'proc_open_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
