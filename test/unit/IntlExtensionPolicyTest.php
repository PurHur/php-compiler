<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @group intl_extension_policy */
final class IntlExtensionPolicyTest extends TestCase
{
    public function testGraphemeBuiltinsWithheldUntilIntlLoaded(): void
    {
        self::assertFalse(IntlExtensionPolicy::advertisesBuiltins());
        self::assertFalse(IntlExtensionPolicy::advertisesLocale());
        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'grapheme_strlen')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'locale_get_default')
        );
        self::assertFalse(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'Locale')
        );
    }
}
