<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\intl\VmLocale;
use PHPUnit\Framework\TestCase;

/** Locale::filterMatches canonicalize flag (#20939). */
final class VmLocaleFilterMatchesCanonicalizeTest extends TestCase
{
    public function testKeywordSeparatorOnlyAfterCanonicalize(): void
    {
        $this->assertFalse(VmLocale::filterMatches('en_US@currency=usd', 'en_US', false));
        $this->assertTrue(VmLocale::filterMatches('en_US@currency=usd', 'en_US', true));
        $this->assertTrue(VmLocale::filterMatches('en_US@currency=usd', 'en', false));
    }

    public function testGrandfatheredAliasNeedsCanonicalize(): void
    {
        $this->assertFalse(VmLocale::filterMatches('i-klingon', 'tlh', false));
        $this->assertTrue(VmLocale::filterMatches('i-klingon', 'tlh', true));
    }

    public function testNonCanonicalPrefixUnchanged(): void
    {
        $this->assertTrue(VmLocale::filterMatches('de-DE', 'de', false));
        $this->assertFalse(VmLocale::filterMatches('fr-FR', 'de', false));
    }
}
