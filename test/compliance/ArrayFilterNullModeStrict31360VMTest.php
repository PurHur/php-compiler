<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array_filter null $mode under strict_types → TypeError (#31360).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ArrayFilterNullModeStrict31360VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_filter_null_mode_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_filter_null_mode_strict.phpt',
            'array_filter_null_mode_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
