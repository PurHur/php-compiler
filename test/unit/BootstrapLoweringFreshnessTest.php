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

    public function testLoweringFingerprintScriptIsSafelyIncludable(): void
    {
        $script = self::$root.'/script/bootstrap-lowering-source-fingerprint.php';
        $code = 'require '.var_export($script, true).'; echo bootstrap_lowering_source_fingerprint('.var_export(self::$root, true).');';
        $out = trim((string) shell_exec('php -r '.escapeshellarg($code)));
        $this->assertSame(64, strlen($out));
    }

    /**
     * Committed prelinked stamp must match live lib/ext fingerprint on a clean checkout.
     * Drift means a refresh stamped a stale env-cached fingerprint (#36145).
     */
    public function testPrelinkedLoweringStampMatchesLiveFingerprint(): void
    {
        $stamp = self::$root.'/prelinked/bootstrap-gen0/.bootstrap_lowering_source.sha';
        $this->assertFileExists($stamp);
        $cmd = 'php '.escapeshellarg(self::$root.'/script/bootstrap-lowering-source-fingerprint.php')
            .' --check '.escapeshellarg($stamp).' 2>&1';
        exec($cmd, $out, $code);
        $joined = implode("\n", $out);
        $this->assertSame(0, $code, $joined);
        $this->assertStringContainsString('OK', $joined);
    }

    public function testLoweringFingerprintResetCacheClearsEnvOverride(): void
    {
        $fresh = trim((string) shell_exec('php '.escapeshellarg(self::$root.'/script/bootstrap-lowering-source-fingerprint.php')));
        $script = self::$root.'/script/bootstrap-lowering-freshness.sh';
        $cmd = 'BOOTSTRAP_LOWERING_SOURCE_FINGERPRINT=deadbeef'
            .' ROOT='.escapeshellarg(self::$root)
            .' bash -lc "source '.escapeshellarg($script)
            .' && bootstrap_lowering_source_fingerprint_reset_cache'
            .' && bootstrap_lowering_source_fingerprint"';
        $after = trim((string) shell_exec($cmd));
        $this->assertSame($fresh, $after);
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
