<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: filter_* soft-null $type/$input_type E_DEPRECATED + strict TypeError (#31486).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class FilterInputNullType31486VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_input_null_type_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_input_null_type_soft.phpt',
            'filter_input_null_type_soft.phpt'
        );
        yield 'filter_input_null_type_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_input_null_type_strict.phpt',
            'filter_input_null_type_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
