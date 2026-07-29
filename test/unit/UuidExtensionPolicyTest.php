<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\uuid\UuidExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @group uuid_extension_policy */
final class UuidExtensionPolicyTest extends TestCase
{
    public function testWithholdsOnReferenceWithoutHostUuid(): void
    {
        if (\extension_loaded('uuid') || \PHPCompiler\CompilerVersion::supportsUuid()) {
            $this->markTestSkipped('uuid advertised on this host/profile');
        }

        self::assertFalse(UuidExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('uuid')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'uuid_create')
        );
    }

    public function testRunsUuidComplianceSkipsPhantomWhenAdvertised(): void
    {
        if (!UuidExtensionPolicy::advertisesExtension()) {
            self::markTestSkipped('uuid not advertised');
        }
        self::assertTrue(UuidExtensionPolicy::runsUuidCompliance('uuid_create_random'));
        self::assertFalse(UuidExtensionPolicy::runsUuidCompliance('extension_loaded_uuid_phantom'));
    }

    public function testRunsUuidCompliancePhantomOnlyWhenWithheld(): void
    {
        if (UuidExtensionPolicy::advertisesExtension()) {
            self::markTestSkipped('uuid advertised');
        }
        self::assertFalse(UuidExtensionPolicy::runsUuidCompliance('uuid_create_random'));
        self::assertTrue(UuidExtensionPolicy::runsUuidCompliance('extension_loaded_uuid_phantom'));
    }
}
