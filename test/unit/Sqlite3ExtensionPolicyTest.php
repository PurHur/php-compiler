<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #22791 */
final class Sqlite3ExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceProfileWithoutHostExt(): void
    {
        if (\extension_loaded('sqlite3')) {
            $this->markTestSkipped('host has ext/sqlite3');
        }
        $this->assertFalse(CompilerVersion::supportsSqlite3());
        $this->assertFalse(Sqlite3ExtensionPolicy::advertisesExtensionLoaded());
        $this->assertFalse(Sqlite3ExtensionPolicy::advertisesExtension());
        $this->assertFalse(Sqlite3ExtensionPolicy::advertisesExceptionClass());
    }

    public function testAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsSqlite3());
            $this->assertTrue(Sqlite3ExtensionPolicy::advertisesExtensionLoaded());
            $this->assertTrue(Sqlite3ExtensionPolicy::advertisesExtension());
            $this->assertTrue(Sqlite3ExtensionPolicy::advertisesExceptionClass());
            $this->assertFalse(CompilerVersion::supportsSqlite3Php85Apis());
            $this->assertFalse(Sqlite3ExtensionPolicy::advertisesPhp85Apis());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #27594 */
    public function testPhp85ApisWithheldOn84AdvertisedOn85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsSqlite3Php85Apis());
            $this->assertFalse(Sqlite3ExtensionPolicy::advertisesPhp85Apis());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }

        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsSqlite3());
            $this->assertTrue(CompilerVersion::supportsSqlite3Php85Apis());
            $this->assertTrue(Sqlite3ExtensionPolicy::advertisesPhp85Apis());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #27594 */
    public function testBusyMethodGatedByPhp85Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $stmt = $runtime->vmContext->classes['sqlite3stmt'] ?? null;
            self::assertNotNull($stmt);
            self::assertArrayNotHasKey('busy', $stmt->methods);
            self::assertArrayNotHasKey('explain', $stmt->methods);
            self::assertArrayNotHasKey('setexplain', $stmt->methods);
            self::assertArrayNotHasKey('EXPLAIN_MODE_PREPARED', $stmt->constants);
            $result = $runtime->vmContext->classes['sqlite3result'] ?? null;
            self::assertNotNull($result);
            self::assertArrayNotHasKey('fetchall', $result->methods);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }

        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            if (!Sqlite3ExtensionPolicy::advertisesExtension()) {
                self::markTestSkipped('sqlite3 withheld (#22791)');
            }
            $runtime = new Runtime();
            $stmt = $runtime->vmContext->classes['sqlite3stmt'] ?? null;
            self::assertNotNull($stmt);
            self::assertArrayHasKey('busy', $stmt->methods);
            self::assertArrayHasKey('explain', $stmt->methods);
            self::assertArrayHasKey('setexplain', $stmt->methods);
            self::assertArrayHasKey('EXPLAIN_MODE_PREPARED', $stmt->constants);
            $result = $runtime->vmContext->classes['sqlite3result'] ?? null;
            self::assertNotNull($result);
            self::assertArrayHasKey('fetchall', $result->methods);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
