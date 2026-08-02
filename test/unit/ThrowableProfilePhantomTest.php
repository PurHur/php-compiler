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
    public function testDateExceptionHierarchyWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesDateExceptionHierarchy());
        $this->assertFalse(CompilerVersion::advertisesRequestParseBodyExceptionClass());
        $this->assertFalse(CompilerVersion::advertisesFiberStackOverflowClass());
    }

    public function testThrowableManifestWithholdsDateHierarchyOnReferenceProfile(): void
    {
        $this->assertFalse(ThrowableManifest::isAdvertised('DateException'));
        $this->assertFalse(ThrowableManifest::isAdvertised('DateError'));
        $this->assertFalse(ThrowableManifest::isAdvertised('DateInvalidTimeZoneException'));
        $this->assertFalse(ThrowableManifest::isAdvertised('DateMalformedIntervalStringException'));
        $this->assertFalse(ThrowableManifest::isAdvertised('DateMalformedStringException'));
        $this->assertFalse(ThrowableManifest::isAdvertised('DateMalformedPeriodStringException'));
        $this->assertFalse(ThrowableManifest::isAdvertised('DateMalformedIntervalException'));
        $this->assertFalse(ThrowableManifest::isAdvertised('DateMalformedPeriodException'));
        $this->assertTrue(ThrowableManifest::isAdvertised('Exception'));
        $this->assertFalse(ThrowableManifest::isAdvertised('RequestParseBodyException'));
        $this->assertFalse(ThrowableManifest::isAdvertised('FiberStackOverflow'));
        $this->assertFalse(ThrowableManifest::isAdvertised('SQLite3Exception'));
    }

    public function testVmOmitsSqlite3ExceptionOnReferenceProfile(): void
    {
        if (\extension_loaded('sqlite3')) {
            $this->markTestSkipped('host has ext/sqlite3');
        }
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->classes['sqlite3exception']));
    }

    public function testVmOmitsDateHierarchyOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->classes['dateexception']));
        $this->assertFalse(isset($ctx->classes['dateerror']));
        $this->assertFalse(isset($ctx->classes['dateinvalidtimezoneexception']));
        $this->assertFalse(isset($ctx->classes['datemalformedintervalstringexception']));
        $this->assertFalse(isset($ctx->classes['datemalformedstring']));
        $this->assertFalse(isset($ctx->classes['datemalformedperiodstringexception']));
        $this->assertFalse(isset($ctx->classes['datemalformedintervalexception']));
        $this->assertFalse(isset($ctx->classes['datemalformedperiodexception']));
        $this->assertFalse(isset($ctx->classes['requestparsebodyexception']));
        $this->assertFalse(isset($ctx->classes['fiberstackoverflow']));
        $this->assertFalse(isset($ctx->classes['sqlite3exception']));
        $this->assertTrue(isset($ctx->classes['exception']));
    }

    public function testSqlite3ExceptionAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsSqlite3());
            $this->assertTrue(\PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy::advertisesExtension());
            $this->assertTrue(\PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy::advertisesExceptionClass());
            $this->assertTrue(ThrowableManifest::isAdvertised('SQLite3Exception'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->classes['sqlite3exception']));
            $this->assertSame('exception', $ctx->classes['sqlite3exception']->parentLc);
            $this->assertTrue(isset($ctx->classes['sqlite3']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
