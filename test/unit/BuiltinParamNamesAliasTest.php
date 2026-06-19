<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\BuiltinParamNames;
use PHPUnit\Framework\TestCase;

final class BuiltinParamNamesAliasTest extends TestCase
{
    public function testNumberFormatDecPointAliasResolvesToDecimalSeparatorIndex(): void
    {
        $names = BuiltinParamNames::forFunction('number_format');
        self::assertNotNull($names);
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'dec_point', 'number_format'));
        self::assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'thousands_sep', 'number_format'));
        self::assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'decimal_separator', 'number_format'));
    }
}
