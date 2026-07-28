<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\tidy\TidyExtensionPolicy;
use PHPCompiler\ext\tidy\VmTidy;
use PHPUnit\Framework\TestCase;

/** TidyExtensionPolicy phantom withhold on reference profile (#23955). */
final class TidyExtensionPolicyTest extends TestCase
{
    public function testAdvertisesExtensionMatchesHostTidy(): void
    {
        self::assertSame(VmTidy::hostAvailable(), TidyExtensionPolicy::advertisesExtension());
    }

    public function testRunsTidyComplianceSkipsPhantomWhenHostHasTidy(): void
    {
        if (!VmTidy::hostAvailable()) {
            self::markTestSkipped('host ext/tidy not available');
        }
        self::assertTrue(TidyExtensionPolicy::runsTidyCompliance('tidy_parse_string_registered'));
        self::assertFalse(TidyExtensionPolicy::runsTidyCompliance('tidy_phantom'));
    }

    public function testRunsTidyCompliancePhantomOnlyWhenHostLacksTidy(): void
    {
        if (VmTidy::hostAvailable()) {
            self::markTestSkipped('host ext/tidy available');
        }
        self::assertFalse(TidyExtensionPolicy::runsTidyCompliance('tidy_parse_string_registered'));
        self::assertTrue(TidyExtensionPolicy::runsTidyCompliance('tidy_phantom'));
    }
}
