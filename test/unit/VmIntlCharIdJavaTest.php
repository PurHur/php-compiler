<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\VmIntlChar;
use PHPUnit\Framework\TestCase;

/** Direct VmIntlChar ID/Java/ISO-control helpers (#20938). */
final class VmIntlCharIdJavaTest extends TestCase
{
    public function testIdJavaIsoAsciiSmoke(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            self::markTestSkipped('intl not advertised');
        }
        $this->assertTrue(VmIntlChar::isIDStart('A'));
        $this->assertFalse(VmIntlChar::isIDStart('1'));
        $this->assertTrue(VmIntlChar::isIDPart('1'));
        $this->assertTrue(VmIntlChar::isIDIgnorable("\x00"));
        $this->assertTrue(VmIntlChar::isISOControl("\n"));
        $this->assertTrue(VmIntlChar::isJavaIDStart('A'));
        $this->assertTrue(VmIntlChar::isJavaIDPart('$'));
        $this->assertTrue(VmIntlChar::isJavaSpaceChar(' '));
        $this->assertFalse(VmIntlChar::isJavaSpaceChar("\t"));
        $this->assertSame('', VmIntlChar::getFC_NFKC_Closure('a'));
    }
}
