<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for str_getcsv(). */
final class StrGetcsvVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_getcsv.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_getcsv.phpt',
            'str_getcsv.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
