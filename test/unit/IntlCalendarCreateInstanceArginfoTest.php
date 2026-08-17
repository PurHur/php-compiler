<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinParamNames;
use PHPUnit\Framework\TestCase;

/** IntlCalendar::createInstance / intlcal_create_instance Reflection arginfo (#28482, #27944). */
final class IntlCalendarCreateInstanceArginfoTest extends TestCase
{
    public function testMethodArginfoTables(): void
    {
        $this->assertSame(2, BuiltinParamNames::paramCountForInternalMethod('IntlCalendar', 'createInstance'));
        $this->assertSame(0, BuiltinParamNames::requiredParamCountForInternalMethod('IntlCalendar', 'createInstance'));
        $this->assertSame(
            ['timezone=', 'locale='],
            BuiltinParamNames::forClassMethod('intlcalendar::createinstance')
        );
        $tz = BuiltinInternalArgInfo::paramInfoForClassMethod('IntlCalendar', 'createInstance', 0);
        $this->assertNotNull($tz);
        $this->assertSame('timezone', $tz['name']);
        $this->assertSame('', $tz['type']);
        $this->assertTrue($tz['isOptional']);
        $locale = BuiltinInternalArgInfo::paramInfoForClassMethod('IntlCalendar', 'createInstance', 1);
        $this->assertNotNull($locale);
        $this->assertSame('locale', $locale['name']);
        $this->assertSame('?string', $locale['type']);
        $this->assertTrue($locale['isOptional']);
        $this->assertSame(
            '?string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('intlcalendar', 'createinstance', 1)
        );
        $this->assertNull(
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('intlcalendar', 'createinstance', 0)
        );
    }

    public function testProceduralArginfoTables(): void
    {
        $fn = 'intlcal_create_instance';
        $this->assertSame(2, BuiltinParamNames::paramCountForInternalFunction($fn));
        $this->assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
        $this->assertSame(['timezone=', 'locale='], BuiltinParamNames::forFunction($fn));
        $this->assertSame('?IntlCalendar', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn));
        $this->assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1));
        $tz = BuiltinInternalArgInfo::paramInfoForFunction($fn, 0);
        $this->assertNotNull($tz);
        $this->assertSame('timezone', $tz['name']);
        $this->assertSame('', $tz['type']);
        $this->assertTrue($tz['isOptional']);
        $locale = BuiltinInternalArgInfo::paramInfoForFunction($fn, 1);
        $this->assertNotNull($locale);
        $this->assertSame('locale', $locale['name']);
        $this->assertSame('?string', $locale['type']);
        $this->assertTrue($locale['isOptional']);
        $names = BuiltinParamNames::forFunction($fn);
        $this->assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'timezone', $fn));
        $this->assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'locale', $fn));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'tz', $fn));
    }
}
