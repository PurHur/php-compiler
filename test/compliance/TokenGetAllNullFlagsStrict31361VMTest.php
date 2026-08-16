<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: token_get_all(..., null) $flags under strict_types → TypeError (#31361).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class TokenGetAllNullFlagsStrict31361VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'token_get_all_null_flags_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_get_all_null_flags_strict.phpt',
            'token_get_all_null_flags_strict.phpt'
        );
        yield 'token_get_all_null_flags_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_get_all_null_flags_soft_dep.phpt',
            'token_get_all_null_flags_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
