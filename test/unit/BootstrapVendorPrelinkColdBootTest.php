<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group unit */
final class BootstrapVendorPrelinkColdBootTest extends TestCase
{
    /** Issue #2881: vendor absent + missing .o — rebuild from committed sources snapshot. */
    public function testVendorAbsentRebuildsMissingPrelinkFromSources(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root.'/script/bootstrap-vendor-prelink-lib.php';
        if (!is_dir($root.'/vendor')) {
            $this->markTestSkipped('vendor/ not installed');
        }
        if (!bootstrapVendorPrelinkSourcesTreePresent($root)) {
            $this->markTestSkipped('prelinked/bootstrap-vendor/sources not present');
        }
        $vendorBak = $root.'/.phpc-vendor-hygiene-bak';
        if (is_dir($vendorBak)) {
            $this->markTestSkipped('stale '.$vendorBak.' from interrupted test');
        }
        $cfgObj = $root.'/prelinked/bootstrap-vendor/ircmaxell-php-cfg.o';
        $llvmObj = $root.'/prelinked/bootstrap-vendor/ircmaxell-php-llvm.o';
        if (!is_file($cfgObj) || !is_file($llvmObj)) {
            $this->markTestSkipped('committed prelink .o not present');
        }
        $cfgBak = $cfgObj.'.phpc-test-bak';
        $llvmBak = $llvmObj.'.phpc-test-bak';
        rename($cfgObj, $cfgBak);
        rename($llvmObj, $llvmBak);
        $cmd = 'cd '.escapeshellarg($root)
            .' && mv vendor '.escapeshellarg(basename($vendorBak))
            .' && '.escapeshellarg(PHP_BINARY).' script/bootstrap-vendor-objects.php --compile 2>&1'
            .'; code=$?; mv '.escapeshellarg(basename($vendorBak)).' vendor'
            .'; mv '.escapeshellarg($cfgBak).' '.escapeshellarg($cfgObj)
            .'; mv '.escapeshellarg($llvmBak).' '.escapeshellarg($llvmObj)
            .'; exit $code';
        try {
            exec('bash -lc '.escapeshellarg($cmd), $out, $code);
            $joined = implode("\n", $out);
            $this->assertSame(0, $code, $joined);
            $this->assertStringContainsString('materialized vendor/', $joined, $joined);
            $this->assertFileExists($cfgObj, $joined);
            $this->assertFileExists($llvmObj, $joined);
        } finally {
            if (is_file($cfgBak)) {
                rename($cfgBak, $cfgObj);
            }
            if (is_file($llvmBak)) {
                rename($llvmBak, $llvmObj);
            }
        }
    }

    /** Issue #2865: vendor absent — bootstrap-vendor-objects --compile uses committed .o only. */
    public function testVendorAbsentCompileUsesCommittedPrelinkObjects(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_dir($root.'/vendor')) {
            $this->markTestSkipped('vendor/ not installed');
        }
        $vendorBak = $root.'/.phpc-vendor-hygiene-bak';
        if (is_dir($vendorBak)) {
            $this->markTestSkipped('stale '.$vendorBak.' from interrupted test');
        }
        // Subshell so PHPUnit keeps vendor/ loaded; script runs without composer autoload.
        $cmd = 'cd '.escapeshellarg($root)
            .' && mv vendor '.escapeshellarg(basename($vendorBak))
            .' && '.escapeshellarg(PHP_BINARY).' script/bootstrap-vendor-objects.php --compile 2>&1'
            .'; code=$?; mv '.escapeshellarg(basename($vendorBak)).' vendor; exit $code';
        exec('bash -lc '.escapeshellarg($cmd), $out, $code);
        $joined = implode("\n", $out);
        $this->assertSame(0, $code, $joined);
        $this->assertStringContainsString('cold boot', $joined, $joined);
        $this->assertStringContainsString('ircmaxell-php-cfg.o', $joined, $joined);
        $this->assertStringContainsString('ircmaxell-php-llvm.o', $joined, $joined);
    }

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
        $this->assertStringContainsString('bootstrapVendorPrelinkColdBootCompile', $script);
        $this->assertStringContainsString('bootstrapVendorPrelinkCompilePackages', $script);
        $lib = (string) file_get_contents($root.'/script/bootstrap-vendor-prelink-lib.php');
        $this->assertStringContainsString('build/bin-compile-aot', $lib);
        $this->assertStringContainsString('build/selfhost-compile-driver', $lib);
        $this->assertStringContainsString('bootstrapVendorPrelinkColdBootCompile', $lib);
        $this->assertStringContainsString('bootstrapVendorPrelinkSourcesTreePresent', $lib);
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

    /** Issue #3049: vendor FAIL lines surface parseAndCompile / PHPTypes context. */
    public function testExtractCompileFailureDetailPrefersParseAndCompileLine(): void
    {
        require_once dirname(__DIR__, 2).'/script/bootstrap-vendor-prelink-lib.php';

        $output = [
            'helloworld_compile_smoke: native emit failed at phase=parseAndCompile',
            'parseAndCompile failure: target=/compiler/bundle.php: Unknown type declaration found: PHPTypes\\Type The type',
            'RuntimeException in vendor/ircmaxell/php-types/lib/PHPTypes/Type.php:419',
        ];
        $detail = bootstrapVendorPrelinkExtractCompileFailureDetail($output);
        $this->assertSame(
            'parseAndCompile failure: target=/compiler/bundle.php: Unknown type declaration found: PHPTypes\Type The type',
            $detail
        );
    }

    /** Issue #3049: Type.php location when parseAndCompile line absent. */
    public function testExtractCompileFailureDetailFallsBackToTypePhpLine(): void
    {
        require_once dirname(__DIR__, 2).'/script/bootstrap-vendor-prelink-lib.php';

        $output = [
            'helloworld_compile_smoke: parseAndCompile returned null (CFG/compile spine)',
            'RuntimeException in vendor/ircmaxell/php-types/lib/PHPTypes/Type.php:419',
        ];
        $detail = bootstrapVendorPrelinkExtractCompileFailureDetail($output);
        $this->assertSame(
            'RuntimeException in vendor/ircmaxell/php-types/lib/PHPTypes/Type.php:419',
            $detail
        );
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
