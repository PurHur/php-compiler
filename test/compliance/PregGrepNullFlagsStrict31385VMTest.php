<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: preg_grep(..., null) $flags under strict_types → TypeError (#31385).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class PregGrepNullFlagsStrict31385VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'preg_grep_null_flags_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/preg_grep_null_flags_strict.phpt',
            'preg_grep_null_flags_strict.phpt'
        );
        yield 'preg_grep_null_flags_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/preg_grep_null_flags_soft_dep.phpt',
            'preg_grep_null_flags_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
