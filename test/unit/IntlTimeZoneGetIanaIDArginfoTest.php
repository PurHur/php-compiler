<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinParamNames;
use PHPUnit\Framework\TestCase;

/** IntlTimeZone::getIanaID / intltz_get_iana_id Reflection arginfo tables (#21553). */
final class IntlTimeZoneGetIanaIDArginfoTest extends TestCase
{
    public function testMethodArginfoTables(): void
    {
        $this->assertSame(1, BuiltinParamNames::paramCountForInternalMethod('IntlTimeZone', 'getIanaID'));
        $this->assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('IntlTimeZone', 'getIanaID'));
        $this->assertSame(['zoneId'], BuiltinParamNames::forClassMethod('intltimezone::getianaid'));
        $this->assertSame(1, BuiltinInternalArgInfo::paramCountForClassMethod('IntlTimeZone', 'getIanaID'));
        $info = BuiltinInternalArgInfo::paramInfoForClassMethod('IntlTimeZone', 'getIanaID', 0);
        $this->assertNotNull($info);
        $this->assertSame('zoneId', $info['name']);
        $this->assertSame('string', $info['type']);
        $this->assertFalse($info['isOptional']);
    }

    public function testProceduralArginfoTables(): void
    {
        $this->assertSame(1, BuiltinParamNames::paramCountForInternalFunction('intltz_get_iana_id'));
        $this->assertSame(['zoneId'], BuiltinParamNames::forFunction('intltz_get_iana_id'));
        $this->assertSame(1, BuiltinInternalArgInfo::paramCountForFunction('intltz_get_iana_id'));
        $info = BuiltinInternalArgInfo::paramInfoForFunction('intltz_get_iana_id', 0);
        $this->assertNotNull($info);
        $this->assertSame('zoneId', $info['name']);
        $this->assertSame('string', $info['type']);
    }
}
