<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\VmIntlTimeZone;
use PHPUnit\Framework\TestCase;

/** Zoneinfo polyfill for IntlTimeZone::getIanaID (#20926). */
final class VmIntlTimeZoneGetIanaIDTest extends TestCase
{
    public function testUsPacificResolvesToLosAngeles(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            self::markTestSkipped('intl not advertised');
        }
        $this->assertSame('America/Los_Angeles', VmIntlTimeZone::getIanaID('US/Pacific'));
        $this->assertSame('America/Los_Angeles', VmIntlTimeZone::getIanaID('America/Los_Angeles'));
        $this->assertFalse(VmIntlTimeZone::getIanaID('Not/A/Zone'));
    }

    public function testAdvertisementMatchesIcuMajorGate(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            self::markTestSkipped('intl not advertised');
        }
        $want = IntlExtensionPolicy::icuMajorVersion() >= 74;
        $this->assertSame($want, IntlExtensionPolicy::advertisesIanaTimeZoneId());
    }
}
