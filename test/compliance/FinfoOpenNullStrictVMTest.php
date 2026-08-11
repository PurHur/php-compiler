<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: finfo_open(null) / finfo::__construct(null) TypeError under strict_types (#30258). */
final class FinfoOpenNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'finfo_open_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/fileinfo/finfo_open_null_strict.phpt',
            'finfo_open_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
