<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapLoweringFreshnessTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testLoweringFingerprintScriptIsDeterministic(): void
    {
        $script = self::$root.'/script/bootstrap-lowering-source-fingerprint.php';
        $this->assertFileExists($script);
        $a = trim((string) shell_exec('php '.escapeshellarg($script)));
        $b = trim((string) shell_exec('php '.escapeshellarg($script)));
        $this->assertSame(64, strlen($a));
        $this->assertSame($a, $b);
    }

    public function testPrelinkedLoweringStampMatchesLiveTree(): void
    {
        $stamp = self::$root.'/prelinked/bootstrap-gen0/.bootstrap_lowering_source.sha';
        $this->assertFileExists($stamp);
        $cmd = 'php '.escapeshellarg(self::$root.'/script/bootstrap-lowering-source-fingerprint.php')
            .' --check '.escapeshellarg($stamp);
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testResolveScriptEnforcesLoweringFreshness(): void
    {
        $resolver = (string) file_get_contents(self::$root.'/script/bootstrap-resolve-compile-invoke.sh');
        $this->assertStringContainsString('bootstrap-lowering-freshness.sh', $resolver);
        $this->assertStringContainsString('bootstrap_native_compile_driver_lowering_fresh', $resolver);
        $this->assertStringContainsString('#21855', $resolver);
        $install = (string) file_get_contents(self::$root.'/script/bootstrap-gen0-install-prelinked-driver.sh');
        $this->assertStringContainsString('bootstrap_lowering_source_refuse_stale_reuse', $install);
        $this->assertStringContainsString('bootstrap-lowering-freshness.sh', $install);
    }
}
