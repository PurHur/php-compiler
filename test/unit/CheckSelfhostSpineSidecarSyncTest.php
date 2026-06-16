<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Prelinked gen-0 sidecar stamp drift guard (issue #8703).
 */
final class CheckSelfhostSpineSidecarSyncTest extends TestCase
{
    public function testSidecarSyncPassesWhenStampMatchesSpine(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/spine-sidecar-match-'.getmypid();
        mkdir($tmp, 0775, true);
        $spine = $tmp.'/main.php';
        file_put_contents($spine, "<?php\n// spine probe\n");
        $stamp = $tmp.'/stamp.sha';
        file_put_contents($stamp, hash_file('sha1', $spine));

        $cmd = 'PHP_COMPILER_SPINE_SIDECAR_TEST_SPINE='.escapeshellarg($spine)
            .' PHP_COMPILER_SPINE_SIDECAR_TEST_STAMP='.escapeshellarg($stamp)
            .' '.escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($root.'/script/check-selfhost-spine-sidecar-sync.php')
            .' 2>&1';
        exec($cmd, $out, $code);

        @unlink($spine);
        @unlink($stamp);
        @rmdir($tmp);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('check-selfhost-spine-sidecar-sync: OK', implode("\n", $out));
    }

    public function testSidecarSyncFailsWhenStampDriftsFromSpine(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/spine-sidecar-drift-'.getmypid();
        mkdir($tmp, 0775, true);
        $spine = $tmp.'/main.php';
        file_put_contents($spine, "<?php\n// spine probe\n");
        $stamp = $tmp.'/stamp.sha';
        file_put_contents($stamp, 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');

        $cmd = 'PHP_COMPILER_SPINE_SIDECAR_TEST_SPINE='.escapeshellarg($spine)
            .' PHP_COMPILER_SPINE_SIDECAR_TEST_STAMP='.escapeshellarg($stamp)
            .' '.escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($root.'/script/check-selfhost-spine-sidecar-sync.php')
            .' 2>&1';
        exec($cmd, $out, $code);

        @unlink($spine);
        @unlink($stamp);
        @rmdir($tmp);

        $this->assertSame(1, $code, implode("\n", $out));
        $joined = implode("\n", $out);
        $this->assertStringContainsString('FAILED', $joined);
        $this->assertStringContainsString('bootstrap-gen0-refresh-sidecar', $joined);
    }

    public function testSidecarSyncAllowStaleSidecarOptOut(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/spine-sidecar-waive-'.getmypid();
        mkdir($tmp, 0775, true);
        $spine = $tmp.'/main.php';
        file_put_contents($spine, "<?php\n// spine probe\n");
        $stamp = $tmp.'/stamp.sha';
        file_put_contents($stamp, 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');

        $cmd = 'BOOTSTRAP_ALLOW_STALE_SIDECAR=1'
            .' PHP_COMPILER_SPINE_SIDECAR_TEST_SPINE='.escapeshellarg($spine)
            .' PHP_COMPILER_SPINE_SIDECAR_TEST_STAMP='.escapeshellarg($stamp)
            .' '.escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($root.'/script/check-selfhost-spine-sidecar-sync.php')
            .' 2>&1';
        exec($cmd, $out, $code);

        @unlink($spine);
        @unlink($stamp);
        @rmdir($tmp);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('WAIVED', implode("\n", $out));
    }

    public function testSidecarSyncMasterStampMatchesSpine(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($root.'/script/check-selfhost-spine-sidecar-sync.php')
            .' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
