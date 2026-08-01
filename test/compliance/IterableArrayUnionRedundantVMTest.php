<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: iterable|array / iterable|Traversable are redundant (#26564, #26591).
 */
final class IterableArrayUnionRedundantVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'iterable_array_union_redundant.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/iterable_array_union_redundant.phpt',
            'iterable_array_union_redundant.phpt'
        );
        yield 'array_iterable_union_redundant.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/array_iterable_union_redundant.phpt',
            'array_iterable_union_redundant.phpt'
        );
        yield 'iterable_traversable_union_redundant.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/iterable_traversable_union_redundant.phpt',
            'iterable_traversable_union_redundant.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
