<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy;
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
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
