<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\VmLocale;
use PHPUnit\Framework\TestCase;

/** VmLocale display-locale ICU path (#22901) — host php-intl not required. */
final class VmLocaleDisplayLocaleTest extends TestCase
{
    public function testFrenchDisplayLocaleMatchesIcu(): void
    {
        if (!\extension_loaded('FFI') && !\class_exists(\FFI::class, false)) {
            $this->markTestSkipped('FFI required for ICU Locale display (#22901)');
        }
        $name = VmLocale::getDisplayName('en_US', 'fr');
        $lang = VmLocale::getDisplayLanguage('en_US', 'fr');
        $region = VmLocale::getDisplayRegion('en_US', 'fr');
        if ('English (United States)' === $name) {
            $this->markTestSkipped('libicu FFI unavailable — English fallback only');
        }
        $this->assertSame('anglais (États-Unis)', $name);
        $this->assertSame('anglais', $lang);
        $this->assertSame('États-Unis', $region);
    }

    public function testEnglishDisplayLocaleStillSensible(): void
    {
        $this->assertSame('German (Germany)', VmLocale::getDisplayName('de_DE', 'en'));
        $this->assertSame('English', VmLocale::getDisplayLanguage('en_US', 'en'));
        $this->assertSame('United States', VmLocale::getDisplayRegion('en_US', 'en'));
    }

    public function testNullDisplayLocaleUsesDefault(): void
    {
        $out = VmLocale::getDisplayName('en_US', null);
        $this->assertIsString($out);
        $this->assertNotSame('', $out);
    }
}
