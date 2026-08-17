<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinInternalDefaultValues;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** idn_to_ascii()/idn_to_utf8() Reflection arginfo tables (#25199). */
final class IdnToAsciiReflectionArginfoTest extends TestCase
{
    /** @dataProvider idnFunctionProvider */
    public function testReflectionStubTypes(string $fn): void
    {
        $this->assertSame('string|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0));
        $this->assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1));
        $this->assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 2));
        $this->assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 3));

        $domain = BuiltinInternalArgInfo::paramInfoForFunction($fn, 0);
        $this->assertNotNull($domain);
        $this->assertSame('domain', $domain['name']);
        $this->assertSame('string', $domain['type']);
        $this->assertFalse($domain['isOptional']);

        $flags = BuiltinInternalArgInfo::paramInfoForFunction($fn, 1);
        $this->assertNotNull($flags);
        $this->assertSame('flags', $flags['name']);
        $this->assertTrue($flags['isOptional']);

        $variant = BuiltinInternalArgInfo::paramInfoForFunction($fn, 2);
        $this->assertNotNull($variant);
        $this->assertSame('variant', $variant['name']);
        $this->assertTrue($variant['isOptional']);

        $info = BuiltinInternalArgInfo::paramInfoForFunction($fn, 3);
        $this->assertNotNull($info);
        $this->assertSame('?array', $info['type']);
        $this->assertTrue($info['isOptional']);

        $this->assertSame(
            ['domain', 'flags=', 'variant=', '&idna_info='],
            BuiltinParamNames::forFunction($fn)
        );
        $this->assertSame(
            ['domain', 'flags=', 'variant=', '&idna_info='],
            BuiltinParamNames::paramNamesForInternalFunction($fn)
        );
        $this->assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
        $this->assertSame(4, BuiltinParamNames::paramCountForInternalFunction($fn));

        $names = BuiltinParamNames::forFunction($fn);
        $this->assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'domain', $fn));
        $this->assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'flags', $fn));
        $this->assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'variant', $fn));
        $this->assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'idna_info', $fn));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'options', $fn));

        $this->assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 2, $variant, false));
        $destVariant = new Variable();
        $this->assertTrue(BuiltinInternalDefaultValues::materialize($destVariant, $fn, 2, $variant));
        $this->assertSame(Variable::TYPE_INTEGER, $destVariant->type);
        $this->assertSame(1, $destVariant->toInt());

        $this->assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 3, $info, false));
        $destInfo = new Variable();
        $this->assertTrue(BuiltinInternalDefaultValues::materialize($destInfo, $fn, 3, $info));
        $this->assertSame(Variable::TYPE_NULL, $destInfo->type);
    }

    /** @return list<array{0: string}> */
    public static function idnFunctionProvider(): array
    {
        return [
            ['idn_to_ascii'],
            ['idn_to_utf8'],
        ];
    }
}
