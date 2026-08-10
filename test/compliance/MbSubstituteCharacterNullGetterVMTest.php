<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mb_substitute_character(null) getter under strict_types (#29919, php-src mbstring.c). */
final class MbSubstituteCharacterNullGetterVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_substitute_character_null_getter.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_substitute_character_null_getter.phpt',
            'mb_substitute_character_null_getter.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
