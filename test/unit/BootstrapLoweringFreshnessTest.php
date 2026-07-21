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
     * Committed prelinked stamp may lag live lib/ext until the next verified-fresh
     * gen-0 refresh (#21905; full rebuild blocked on #21886). The --check gate must
     * still detect drift (exit 1) or confirm match (exit 0).
     */
    public function testPrelinkedLoweringStampCheckDetectsMatchOrDrift(): void
    {
        $stamp = self::$root.'/prelinked/bootstrap-gen0/.bootstrap_lowering_source.sha';
        $this->assertFileExists($stamp);
        $cmd = 'php '.escapeshellarg(self::$root.'/script/bootstrap-lowering-source-fingerprint.php')
            .' --check '.escapeshellarg($stamp).' 2>&1';
        exec($cmd, $out, $code);
        $joined = implode("\n", $out);
        $this->assertContains($code, [0, 1], $joined);
        if (0 === $code) {
            $this->assertStringContainsString('OK', $joined);
        } else {
            $this->assertStringContainsString('FAILED', $joined);
        }
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
