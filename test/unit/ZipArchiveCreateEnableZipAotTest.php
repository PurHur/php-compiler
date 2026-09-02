<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ZipArchive class constants follow ZipExtensionPolicy, not PROFILE-only supportsZip (#34412).
 *
 * @see php-src ext/zip/php_zip.c REGISTER_ZIPARCHIVE_CLASS_CONST_*
 *
 * @group llvm
 * @group aot
 */
final class ZipArchiveCreateEnableZipAotTest extends TestCase
{
    public function testEnableZipAotCreateMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (\extension_loaded('zip')) {
            $this->markTestSkipped('host ext/zip loaded');
        }

        $src = __DIR__.'/../repro/issue_34412_ziparchive_create_enable_zip.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testProfile84AloneDoesNotPhantomSeedCreateOnAot(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (\extension_loaded('zip')) {
            $this->markTestSkipped('host ext/zip loaded');
        }

        $src = __DIR__.'/../repro/issue_34412_ziparchive_create_phantom_profile84.php';
        // VM: class not found. AOT must not successfully print CREATE=1 (pre-#34412 phantom
        // under supportsZip-only seed). Compile abort, runtime Error, or empty/null is OK.
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/zip_phant_'.getmypid().'_'.md5($src);
        $compile = 'env -u PHP_COMPILER_ENABLE_ZIP PHP_COMPILER_PROFILE=8.4 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile.' 2>&1', $cout, $crc);
            if (0 === $crc && is_file($bin)) {
                exec(escapeshellarg($bin).' 2>&1', $out, $rc);
                $body = implode("\n", $out);
                $this->assertStringNotContainsString('V=1', $body, $body);
            } else {
                $this->assertNotSame(0, $crc, implode("\n", $cout));
            }
        } finally {
            @unlink($bin);
            chdir($cwd);
        }
    }

    public function testSeedGateUsesExtensionPolicy(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/zip/Module.php');
        $this->assertStringContainsString('ZipExtensionPolicy::advertisesExtension()', $src);
        $this->assertStringContainsString('seedExternalClassConstants($id, ZipArchiveConstants::CLASS_CONSTANTS)', $src);
        // Must not gate ZipArchive ClassConstFetch solely on PROFILE supportsZip().
        $this->assertDoesNotMatchRegularExpression(
            "/ziparchive' === \\\$lcname && CompilerVersion::supportsZip\\(\\)/",
            $src
        );
    }

    private function runVm(string $src): string
    {
        return $this->runEnv(['PHP_COMPILER_ENABLE_ZIP=1'], 'bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/zip_create_'.getmypid().'_'.md5($src);
        $compile = 'env PHP_COMPILER_ENABLE_ZIP=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile.' 2>&1', $cout, $crc);
            $this->assertSame(0, $crc, implode("\n", $cout));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            @unlink($bin);
            chdir($cwd);
        }
    }

    /** @param list<string> $env */
    private function runEnv(array $env, string $script, string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env '.implode(' ', $env).' '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/'.$script).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }
}
