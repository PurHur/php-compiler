<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: highlight_string(..., null) $return under strict_types → TypeError (#31383).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class HighlightStringNullReturnStrict31383VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'highlight_string_null_return_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/highlight_string_null_return_strict.phpt',
            'highlight_string_null_return_strict.phpt'
        );
        yield 'highlight_string_null_return_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/highlight_string_null_return_soft_dep.phpt',
            'highlight_string_null_return_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
