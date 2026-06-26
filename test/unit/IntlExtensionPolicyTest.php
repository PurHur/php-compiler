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
        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'grapheme_strlen')
        );
    }
}
