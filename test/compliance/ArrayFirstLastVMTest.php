<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for array_first() / array_last() (#3491). */
final class ArrayFirstLastVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_first_last.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_first_last.phpt',
            'array_first_last.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
