<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * make north-star5-verify / script/north-star5-verify.sh (issue #1416).
 */
final class NorthStar5VerifyScriptTest extends TestCase
{
    public function testNorthStar5VerifyScriptExistsAndPrintsHelp(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/north-star5-verify.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', $script, '--help'], $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim(($stdout !== false ? $stdout : '')."\n".($stderr !== false ? $stderr : ''));
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('north-star5-verify', $combined);
        $this->assertStringContainsString('bootstrap-vendor-objects.php', $combined);
        $this->assertStringContainsString('bootstrap-spine-count.php', $combined);
        $this->assertStringContainsString('bootstrap-spine-count.php', $combined);
        $this->assertStringContainsString('live N/N', $combined);
        $this->assertStringContainsString('--strict', $combined);
        $this->assertStringContainsString('--fast', $combined);
        $this->assertStringContainsString('BOOTSTRAP_VENDOR_REBUILD_AUDIT', $combined);
        $this->assertStringContainsString('#1416', $combined);
        $this->assertStringContainsString('#1492', $combined);
    }

    public function testNorthStar5VerifyScriptDocumentsSteps(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/north-star5-verify.sh');
        $this->assertStringContainsString('bootstrap-vendor-objects.php', $body);
        $this->assertStringContainsString('bootstrap-selfhost-probe', $body);
        $this->assertStringContainsString('PHP_COMPILER_VENDOR_PRELINK', $body);
        $this->assertStringContainsString('north-star5-verify: OK', $body);
        $this->assertStringContainsString('FAST_M5', $body);
        $this->assertStringContainsString('ns5_fast_ensure_spine_binary', $body);
        $this->assertStringContainsString('ns5_spine_ratio_label', $body);
        $this->assertStringNotContainsString('718/718', $body);
        $this->assertStringNotContainsString('cfg/llvm parse blockers', $body);
        // 4f2 retries live in the probe script (same class as 4f3) — #33501.
        $this->assertStringContainsString('bootstrap-selfhost-vm-driver-execute-probe', $body);
        $this->assertStringContainsString('Retries for gen-0 free(): invalid pointer flake live in the probe script (#33501)', $body);
        $this->assertStringContainsString('ns5_gen0_trust_preflight', $body);
        $this->assertStringContainsString('bootstrap-trust-preflight.sh', $body);
        $this->assertStringContainsString('step 3t: gen-0 trust preflight', $body);
        $this->assertStringContainsString('bootstrap-gen0-driver-functional-smoke', $body);
        $this->assertStringContainsString('ns5_run 3f', $body);
        $this->assertStringContainsString('#36218', $body);
        $this->assertStringContainsString('#36145', $body);
    }

    public function testMakefileDeclaresNorthStar5VerifyTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('north-star5-verify:', $makefile);
        $this->assertStringContainsString('north-star5-verify-fast:', $makefile);
        $this->assertStringContainsString('script/north-star5-verify.sh', $makefile);
    }
}
