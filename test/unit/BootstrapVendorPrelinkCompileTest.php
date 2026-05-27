<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group unit */
final class BootstrapVendorPrelinkCompileTest extends TestCase
{
    public function testVendorBundlesLintWithoutComposerAutoload(): void
    {
        $root = dirname(__DIR__, 2);
        $packages = ['ircmaxell-php-types', 'ircmaxell-php-cfg', 'ircmaxell-php-llvm'];
        $missing = [];
        foreach ($packages as $slug) {
            $bundle = $root.'/test/bootstrap-vendor-prelink/generated/'.$slug.'_bundle.php';
            if (!is_file($bundle)) {
                $missing[] = $slug;
            }
        }
        if ([] !== $missing) {
            $this->markTestSkipped('vendor prelink bundles missing: '.implode(', ', $missing));
        }

        foreach ($packages as $slug) {
            $bundle = $root.'/test/bootstrap-vendor-prelink/generated/'.$slug.'_bundle.php';
            $cmd = 'PHP_COMPILER_VENDOR_PRELINK=1 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php').' -l '.escapeshellarg($bundle).' 2>&1';
            exec($cmd, $out, $code);
            $joined = implode("\n", $out);
            $this->assertStringNotContainsString(
                'Missing vendor autoload',
                $joined,
                $slug.': '.$joined
            );
            $this->assertNotSame(1, $code, $slug.' lint exited 1: '.$joined);
        }
    }
}
