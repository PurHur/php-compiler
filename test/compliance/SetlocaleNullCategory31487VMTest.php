<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: setlocale(null, …) $category — soft DEP + strict TypeError (#31487).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class SetlocaleNullCategory31487VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'setlocale_null_category_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/setlocale_null_category_soft_dep.phpt',
            'setlocale_null_category_soft_dep.phpt'
        );
        yield 'setlocale_null_category_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/setlocale_null_category_strict.phpt',
            'setlocale_null_category_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
