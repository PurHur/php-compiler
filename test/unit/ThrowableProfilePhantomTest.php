<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPCompiler\ext\standard\ThrowableManifest;
use PHPUnit\Framework\TestCase;

/** @covers issue #13118, #13124, #15382 */
final class ThrowableProfilePhantomTest extends TestCase
{
    public function testForwardCompatDateHierarchyOn84DevProfile(): void
    {
        $this->assertTrue(CompilerVersion::advertisesDateExceptionHierarchy());
        $this->assertFalse(CompilerVersion::advertisesRequestParseBodyExceptionClass());
    }

    public function testThrowableManifestAdvertisesDateHierarchyOn84DevProfile(): void
    {
        $this->assertTrue(ThrowableManifest::isAdvertised('DateException'));
        $this->assertTrue(ThrowableManifest::isAdvertised('DateError'));
        $this->assertTrue(ThrowableManifest::isAdvertised('DateInvalidTimeZoneException'));
        $this->assertTrue(ThrowableManifest::isAdvertised('DateMalformedIntervalException'));
        $this->assertTrue(ThrowableManifest::isAdvertised('DateMalformedPeriodException'));
        $this->assertTrue(ThrowableManifest::isAdvertised('Exception'));
        $this->assertFalse(ThrowableManifest::isAdvertised('RequestParseBodyException'));
    }

    public function testVmRegistersDateHierarchyOn84DevProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertTrue(isset($ctx->classes['dateexception']));
        $this->assertTrue(isset($ctx->classes['dateerror']));
        $this->assertTrue(isset($ctx->classes['dateinvalidtimezoneexception']));
        $this->assertTrue(isset($ctx->classes['datemalformedintervalexception']));
        $this->assertTrue(isset($ctx->classes['datemalformedperiodexception']));
        $this->assertFalse(isset($ctx->classes['requestparsebodyexception']));
        $this->assertTrue(isset($ctx->classes['exception']));
    }
}
