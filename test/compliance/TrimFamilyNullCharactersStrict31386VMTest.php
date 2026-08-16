<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: trim/ltrim/rtrim/chop(..., null) $characters under strict_types → TypeError (#31386).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class TrimFamilyNullCharactersStrict31386VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'trim_family_null_characters_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/trim_family_null_characters_strict.phpt',
            'trim_family_null_characters_strict.phpt'
        );
        yield 'trim_family_null_characters_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/trim_family_null_characters_soft_dep.phpt',
            'trim_family_null_characters_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
