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

    public function testVendorObjectsScriptUsesCompiledDriverResolver(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/bootstrap-vendor-objects.php');
        $this->assertStringContainsString('bootstrapVendorPrelinkResolveCompileInvoker', $script);
        $this->assertStringContainsString('bootstrapVendorPrelinkBuildCompileCommand', $script);
        $lib = (string) file_get_contents($root.'/script/bootstrap-vendor-prelink-lib.php');
        $this->assertStringContainsString('build/bin-compile-aot', $lib);
        $this->assertStringContainsString('build/selfhost-compile-driver', $lib);
    }

    public function testResolveCompileInvokerPrefersNativeDriverWhenPresent(): void
    {
        require_once dirname(__DIR__, 2).'/script/bootstrap-vendor-prelink-lib.php';

        $root = sys_get_temp_dir().'/phpc-vendor-prelink-'.getmypid();
        mkdir($root.'/build', 0775, true);
        $driver = $root.'/build/bin-compile-aot';
        file_put_contents($driver, "#!/bin/sh\necho stub\n");
        chmod($driver, 0755);

        $prev = getenv('BOOTSTRAP_GEN0_ZEND_ONLY');
        putenv('BOOTSTRAP_GEN0_ZEND_ONLY=0');

        try {
            $inv = bootstrapVendorPrelinkResolveCompileInvoker($root);
            $this->assertSame('native', $inv['mode']);
            $this->assertSame($driver, $inv['argv'][0]);
        } finally {
            unlink($driver);
            rmdir($root.'/build');
            rmdir($root);
            if (false === $prev) {
                putenv('BOOTSTRAP_GEN0_ZEND_ONLY');
            } else {
                putenv('BOOTSTRAP_GEN0_ZEND_ONLY='.$prev);
            }
        }
    }

    /** Issue #2723: pre-plugin autoload must not pass deprecated spl_autoload_register do_throw. */
    public function testBootstrapGatesAvoidSplAutoloadRegisterNotice(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-inventory.php').' --check 2>&1';
        exec($cmd, $out, $code);
        $joined = implode("\n", $out);
        $this->assertSame(0, $code, $joined);
        $this->assertStringNotContainsString('spl_autoload_register(): Argument #2', $joined, $joined);
        $this->assertStringNotContainsString('has been ignored', $joined, $joined);
    }

    /** Issue #2729: php-cfg Assertion patch must not emit dynamic-property deprecations. */
    public function testSelfhostCompileAvoidsAssertionDynamicPropertyDeprecation(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/vendor/autoload.php')) {
            $this->markTestSkipped('vendor/ not installed');
        }

        $root = dirname(__DIR__, 2);
        $cmd = 'source '.escapeshellarg($root.'/script/php-env.sh').'; '
            .'PHP_COMPILER_SELFHOST_AOT=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -l '
            .escapeshellarg($root.'/test/selfhost/compiler_minimal/main.php').' 2>&1';
        exec('bash -lc '.escapeshellarg($cmd), $out, $code);
        $joined = implode("\n", $out);
        $this->assertStringNotContainsString(
            'Creation of dynamic property PHPCfg\\Op\\Expr\\Assertion::$expr is deprecated',
            $joined,
            $joined
        );
    }
}
