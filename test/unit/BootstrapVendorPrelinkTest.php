<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * M5 vendor prelink bundles and manifest (issue #1416).
 */
final class BootstrapVendorPrelinkTest extends TestCase
{
    public function testVendorPrelinkBundlesAndManifestAreFresh(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-vendor-objects.php').' --check 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testVendorPrelinkManifestListsTargetPackages(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = $root.'/prelinked/bootstrap-vendor/manifest.json';
        $this->assertFileExists($manifest);
        $data = json_decode((string) file_get_contents($manifest), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('packages', $data);
        foreach (['ircmaxell/php-cfg', 'ircmaxell/php-types', 'ircmaxell/php-llvm'] as $package) {
            $this->assertArrayHasKey($package, $data['packages']);
            $this->assertGreaterThan(0, $data['packages'][$package]['php_files']);
        }
    }

    public function testLinkerPrelinkedVendorObjectPathsReadsManifest(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = \PHPCompiler\AOT\Linker::prelinkedVendorObjectPaths($root);
        $this->assertIsArray($paths);
        foreach ($paths as $rel) {
            $this->assertFileExists($root.'/'.$rel);
        }
    }
}
