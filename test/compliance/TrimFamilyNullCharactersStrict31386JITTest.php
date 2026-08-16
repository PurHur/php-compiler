<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: trim/ltrim/rtrim/chop(..., null) $characters under strict_types → TypeError (#31386). */
final class TrimFamilyNullCharactersStrict31386JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'trim_family_null_characters_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/trim_family_null_characters_strict_jit.phpt',
            'trim_family_null_characters_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
