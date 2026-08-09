<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: metaphone(..., -1) ValueError for $max_phonemes (#29304, php-src string.c). */
final class MetaphoneMaxPhonemesValueErrorVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'metaphone_max_phonemes_valueerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/metaphone_max_phonemes_valueerror.phpt',
            'metaphone_max_phonemes_valueerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
