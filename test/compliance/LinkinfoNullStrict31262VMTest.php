<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: linkinfo(null) under strict_types → TypeError (#31262).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class LinkinfoNullStrict31262VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'linkinfo_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/linkinfo_null_strict.phpt',
            'linkinfo_null_strict.phpt'
        );
        yield 'linkinfo_null_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/linkinfo_null_soft_dep.phpt',
            'linkinfo_null_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
