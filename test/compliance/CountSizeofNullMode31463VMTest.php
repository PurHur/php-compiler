<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: count/sizeof(..., null) $mode — soft DEP + strict TypeError (#31463).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class CountSizeofNullMode31463VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'count_sizeof_null_mode_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/count_sizeof_null_mode_soft_dep.phpt',
            'count_sizeof_null_mode_soft_dep.phpt'
        );
        yield 'count_sizeof_null_mode_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/count_sizeof_null_mode_strict.phpt',
            'count_sizeof_null_mode_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
