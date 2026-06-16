<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group unit */
final class BootstrapVendorNativeRebuildAuditTest extends TestCase
{
    public function testAuditScriptExistsAndDocumentsUsage(): void
    {
        $root = dirname(__DIR__, 2);
        $sh = $root.'/script/bootstrap-vendor-native-rebuild-audit.sh';
        $php = $root.'/script/bootstrap-vendor-native-rebuild-audit.php';
        $this->assertFileExists($sh);
        $this->assertFileExists($php);
        $this->assertTrue(is_executable($sh));

        $body = (string) file_get_contents($php);
        $this->assertStringContainsString('bootstrap-vendor-native-rebuild-audit', $body);
        $this->assertStringContainsString('BOOTSTRAP_M5_VENDOR_ALLOW_ZEND=0', $body);
        $this->assertStringContainsString('Issue template', $body);
        $this->assertStringContainsString('#8718', $body);
    }

    public function testNorthStar5VerifyDocumentsVendorRebuildAuditGate(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/north-star5-verify.sh');
        $this->assertStringContainsString('BOOTSTRAP_VENDOR_REBUILD_AUDIT', $body);
        $this->assertStringContainsString('bootstrap-vendor-native-rebuild-audit.sh', $body);
    }

    public function testLibExposesAuditHelpers(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root.'/script/bootstrap-vendor-prelink-lib.php';

        $this->assertTrue(function_exists('bootstrapVendorPrelinkAuditPackage'));
        $this->assertTrue(function_exists('bootstrapVendorPrelinkSourcesContentHash'));
        if (!bootstrapVendorPrelinkSourcesTreePresent($root)) {
            $this->markTestSkipped('prelinked/bootstrap-vendor/sources not present');
        }
        $hash = bootstrapVendorPrelinkSourcesContentHash($root, 'ircmaxell/php-types');
        $this->assertIsString($hash);
        $this->assertSame(64, strlen($hash));
    }

    public function testMakefileDeclaresVendorRebuildAuditTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('bootstrap-vendor-native-rebuild-audit:', $makefile);
    }
}
