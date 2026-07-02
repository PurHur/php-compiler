<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmEnv;
use PHPCompiler\ext\standard\VmIniIntrospection;
use PHPUnit\Framework\TestCase;

/** VmIniIntrospection host seed + env fallback (#9175, #15111). */
final class VmIniIntrospectionTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_INI_LOADED_FILE');
        putenv('PHP_COMPILER_INI_SCANNED_FILES');
        VmIniIntrospection::resetIniSnapshotForTesting();
    }

    public function testSeedHostIniEnvFromZendPopulatesWhenUnset(): void
    {
        if (!function_exists('php_ini_scanned_files')) {
            $this->markTestSkipped('Host PHP lacks php_ini_scanned_files()');
        }

        putenv('PHP_COMPILER_INI_LOADED_FILE');
        putenv('PHP_COMPILER_INI_SCANNED_FILES');

        VmIniIntrospection::seedHostIniEnvFromZend();

        $hostScanned = php_ini_scanned_files();
        $seeded = VmIniIntrospection::scannedFiles();
        if (false === $hostScanned) {
            $this->assertFalse($seeded);
        } else {
            $this->assertSame($hostScanned, $seeded);
        }
    }

    public function testSeedHostIniEnvFromZendDoesNotOverrideExplicitEnv(): void
    {
        putenv('PHP_COMPILER_INI_SCANNED_FILES=/explicit/a.ini,');
        VmIniIntrospection::seedHostIniEnvFromZend();
        $this->assertSame('/explicit/a.ini,', VmIniIntrospection::scannedFiles());
    }

    public function testLoadedFileIgnoresVmEnvPutenvOverlayAfterFreeze(): void
    {
        VmIniIntrospection::seedHostIniEnvFromZend();
        $before = VmIniIntrospection::loadedFile();
        VmEnv::putenv('PHP_COMPILER_INI_LOADED_FILE=/etc/custom/php.ini');
        $this->assertSame($before, VmIniIntrospection::loadedFile());
    }
}
