<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\BuiltinParamNames;
use PHPUnit\Framework\TestCase;

final class BuiltinParamNamesAliasTest extends TestCase
{
    public function testNumberFormatCanonicalNamedParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('number_format');
        self::assertNotNull($names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'decimal_separator', 'number_format'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'thousands_separator', 'number_format'));
    }

    public function testNumberFormatLegacyAliasNamesAreRejected(): void
    {
        $names = BuiltinParamNames::forFunction('number_format');
        self::assertNotNull($names);
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'dec_point', 'number_format'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'thousands_sep', 'number_format'));
    }

    public function testImplodeNamedSeparatorAndArrayResolve(): void
    {
        $names = BuiltinParamNames::forFunction('implode');
        self::assertSame(['separator', 'array'], $names);
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'separator', 'implode'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'array', 'implode'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'glue', 'implode'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'pieces', 'implode'));
    }

    public function testHtmlspecialcharsDoubleEncodeNamedParamResolves(): void
    {
        $names = BuiltinParamNames::forFunction('htmlspecialchars');
        self::assertSame(['string', 'flags', 'encoding', 'double_encode'], $names);
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'double_encode', 'htmlspecialchars'));
        $entities = BuiltinParamNames::forFunction('htmlentities');
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($entities, 'double_encode', 'htmlentities'));
    }

    public function testLevenshteinNamedCostParamsResolve(): void
    {
        $names = BuiltinParamNames::forFunction('levenshtein');
        self::assertSame(
            ['string1', 'string2', 'insertion_cost', 'replacement_cost', 'deletion_cost'],
            $names
        );
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'insertion_cost', 'levenshtein'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'replacement_cost', 'levenshtein'));
        self::assertSame(4, BuiltinParamNames::lookupNamedParamIndex($names, 'deletion_cost', 'levenshtein'));
    }
}
