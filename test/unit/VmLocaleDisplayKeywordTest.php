<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\intl\VmLocale;
use PHPUnit\Framework\TestCase;

/** Locale display keyword APIs (#20928). */
final class VmLocaleDisplayKeywordTest extends TestCase
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
        $this->assertFalse(CompilerVersion::advertisesLocaleDisplayKeyword());
    }

    public function testGateAdvertisedOnProfile85(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $this->assertTrue(CompilerVersion::advertisesLocaleDisplayKeyword());
    }

    public function testDisplayKeywordLabels(): void
    {
        $this->assertSame('Currency', VmLocale::getDisplayKeyword('currency', 'en'));
        $this->assertSame('Sort Order', VmLocale::getDisplayKeyword('collation', 'en'));
    }

    public function testDisplayKeywordValue(): void
    {
        $this->assertSame(
            'Euro',
            VmLocale::getDisplayKeywordValue('de_DE@currency=EUR', 'currency', 'en')
        );
        $this->assertSame(
            'US Dollar',
            VmLocale::getDisplayKeywordValue('en_US@currency=USD', 'currency', 'en')
        );
    }
}
