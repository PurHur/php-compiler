<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mb_substitute_character(null) getter under strict_types (#29919, php-src mbstring.c). */
final class MbSubstituteCharacterNullGetterJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_substitute_character_null_getter_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_substitute_character_null_getter_jit.phpt',
            'mb_substitute_character_null_getter_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
