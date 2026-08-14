<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinParamNames;
use PHPUnit\Framework\TestCase;

/** IntlCalendar::createInstance Reflection arginfo tables (#28482). */
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
}
