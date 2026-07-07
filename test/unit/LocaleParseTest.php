<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\VmLocale;
use PHPUnit\Framework\TestCase;

/** VmLocale BCP-47 tag parsing (#5125). */
final class LocaleParseTest extends TestCase
{
    protected function tearDown(): void
    {
        VmLocale::resetDefaultForTests();
    }

    public function test_primary_region_script_tags(): void
    {
        self::assertSame('en', VmLocale::getPrimaryLanguage('en_US_POSIX'));
        self::assertSame('US', VmLocale::getRegion('en_US_POSIX'));
        self::assertSame('', VmLocale::getScript('en_US_POSIX'));

        self::assertSame('zh', VmLocale::getPrimaryLanguage('zh-Hans-CN'));
        self::assertSame('CN', VmLocale::getRegion('zh-Hans-CN'));
        self::assertSame('Hans', VmLocale::getScript('zh-Hans-CN'));

        self::assertSame('en', VmLocale::getPrimaryLanguage('en'));
        self::assertSame('', VmLocale::getRegion('en'));
    }

    public function test_empty_operand_uses_default_locale(): void
    {
        VmLocale::setDefault('de_DE');
        self::assertSame('de', VmLocale::getPrimaryLanguage(''));
        self::assertSame('DE', VmLocale::getRegion(''));
    }
}
