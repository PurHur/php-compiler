<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\VmIntlChar;
use PHPUnit\Framework\TestCase;

/** Direct VmIntlChar::isWhitespace helper (#22405). */
final class VmIntlCharIsWhitespaceTest extends TestCase
{
    public function testIsWhitespaceAsciiSmoke(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            self::markTestSkipped('intl not advertised');
        }
        $this->assertTrue(VmIntlChar::isWhitespace(0x20));
        $this->assertFalse(VmIntlChar::isWhitespace(0x41));
        $this->assertTrue(VmIntlChar::isWhitespace(0x09));
        // Java/ICU u_isWhitespace excludes no-break space.
        $this->assertFalse(VmIntlChar::isWhitespace(0xA0));
    }
}
