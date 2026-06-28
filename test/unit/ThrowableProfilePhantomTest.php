<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPCompiler\ext\standard\ThrowableManifest;
use PHPUnit\Framework\TestCase;

/** @covers issue #13118 */
final class ThrowableProfilePhantomTest extends TestCase
{
    public function testForwardCompatDateHierarchyWithheldOn84DevProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesDateExceptionHierarchy());
    }

    public function testThrowableManifestGatesDateHierarchyOn84DevProfile(): void
    {
        $this->assertFalse(ThrowableManifest::isAdvertised('DateException'));
        $this->assertFalse(ThrowableManifest::isAdvertised('DateError'));
        $this->assertFalse(ThrowableManifest::isAdvertised('DateInvalidTimeZoneException'));
        $this->assertTrue(ThrowableManifest::isAdvertised('Exception'));
    }

    public function testVmWithholdsDateHierarchyOn84DevProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->classes['dateexception']));
        $this->assertFalse(isset($ctx->classes['dateerror']));
        $this->assertFalse(isset($ctx->classes['dateinvalidtimezoneexception']));
        $this->assertTrue(isset($ctx->classes['exception']));
    }
}
