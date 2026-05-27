<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group unit */
final class BootstrapVendorPrelinkColdBootTest extends TestCase
{
    public function testColdBootCheckPassesWithCommittedBundles(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root.'/script/bootstrap-vendor-prelink-lib.php';

        $manifestPath = $root.'/prelinked/bootstrap-vendor/manifest.json';
        $manifest = bootstrapVendorPrelinkReadManifest($manifestPath);
        $this->assertIsArray($manifest);

        $this->assertSame(0, bootstrapVendorPrelinkColdBootCheck($root, $manifestPath, $manifest));
    }

    public function testVendorPrelinkCompileSkipsAutoloadWhenVendorTreeAbsent(): void
    {
        $root = dirname(__DIR__, 2);
        $bundle = $root.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-types_bundle.php';
        if (!is_file($bundle)) {
            $this->markTestSkipped('vendor prelink bundle not present');
        }

        $cmd = 'PHP_COMPILER_VENDOR_PRELINK=1 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -l '.escapeshellarg($bundle).' 2>&1';
        exec($cmd, $out, $code);
        $joined = implode("\n", $out);
        $this->assertStringNotContainsString('Missing vendor autoload', $joined, $joined);
        $this->assertNotSame(1, $code, 'expected lint to proceed past cli autoload (may fail later without vendor sources)');
    }
}
