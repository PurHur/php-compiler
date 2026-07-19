<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\intl\VmLocale;
use PHPUnit\Framework\TestCase;

/** Locale RTL + likely/minimize subtags (#20927). */
final class VmLocaleRtlLikelySubtagsTest extends TestCase
{
    private ?string $prevProfile = null;

    protected function setUp(): void
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        $this->prevProfile = \is_string($raw) ? $raw : null;
    }

    protected function tearDown(): void
    {
        if (null === $this->prevProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->prevProfile);
        }
    }

    public function testGateWithheldOnDefaultProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        $this->assertFalse(CompilerVersion::advertisesLocaleRtlAndLikelySubtags());
    }

    public function testGateAdvertisedOnProfile85(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $this->assertTrue(CompilerVersion::advertisesLocaleRtlAndLikelySubtags());
    }

    public function testIsRightToLeftMatchesIcu(): void
    {
        $this->assertTrue(VmLocale::isRightToLeft('ar'));
        $this->assertTrue(VmLocale::isRightToLeft('he'));
        $this->assertFalse(VmLocale::isRightToLeft('en'));
        $this->assertFalse(VmLocale::isRightToLeft('de'));
    }

    public function testLikelyAndMinimizeRoundTrip(): void
    {
        $this->assertSame('en_Latn_US', VmLocale::addLikelySubtags('en'));
        $this->assertSame('ar_Arab_EG', VmLocale::addLikelySubtags('ar'));
        $this->assertSame('en', VmLocale::minimizeSubtags('en_Latn_US'));
        $this->assertSame('ar', VmLocale::minimizeSubtags('ar_Arab_EG'));
        $this->assertSame('ja', VmLocale::minimizeSubtags(VmLocale::addLikelySubtags('ja')));
    }
}
