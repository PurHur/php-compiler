<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * Gen-0 parallel chunk plan + orchestrator contracts (#36387).
 */
final class BootstrapGen0ChunksOrchestratorTest extends TestCase
{
    public function testChunkPlanScriptWritesMicroEntriesAndPlan(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-gen0-chunk-plan.php';
        $this->assertFileExists($script);

        $tmp = sys_get_temp_dir().'/phpc-chunk-plan-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/entries', 0755, true);
        $planPath = $tmp.'/plan.json';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
            .' --micro=3'
            .' --entries-dir='.escapeshellarg($tmp.'/entries')
            .' --plan-out='.escapeshellarg($planPath);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFileExists($planPath);
        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertSame(3, $plan['chunk_count']);
        $this->assertCount(3, $plan['chunks']);
        foreach ($plan['chunks'] as $chunk) {
            $this->assertArrayHasKey('chunk_id', $chunk);
            $this->assertArrayHasKey('entry', $chunk);
            $this->assertArrayHasKey('wave', $chunk);
            $this->assertFileExists($chunk['entry']);
            $this->assertStringContainsString('chunk-'.$chunk['chunk_id'], (string) file_get_contents($chunk['entry']));
        }
        $this->removeTree($tmp);
    }

    public function testChunkPlanLibAndExtCarryWavesAndAutoload(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-gen0-chunk-plan.php';
        $tmp = sys_get_temp_dir().'/phpc-chunk-plan-lib-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/entries', 0755, true);
        $planPath = $tmp.'/plan.json';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
            .' --lib=Lint,Func'
            .' --ext=types'
            .' --entries-dir='.escapeshellarg($tmp.'/entries')
            .' --plan-out='.escapeshellarg($planPath);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertGreaterThanOrEqual(3, $plan['chunk_count']);
        $kinds = [];
        $waves = [];
        foreach ($plan['chunks'] as $chunk) {
            $kinds[$chunk['kind']] = true;
            $waves[] = (int) $chunk['wave'];
            $body = (string) file_get_contents($chunk['entry']);
            $this->assertStringContainsString('vendor/autoload.php', $body);
            // Depth-correct __DIR__ relative (not hardcoded ../../../) so nested OUT_DIR works.
            $this->assertMatchesRegularExpression(
                "#require_once __DIR__ \\. '/(\\.\\./)+vendor/autoload\\.php';#",
                $body
            );
            $this->assertGreaterThan(0, (int) $chunk['file_count']);
        }
        $this->assertArrayHasKey('lib', $kinds);
        $this->assertArrayHasKey('ext', $kinds);
        // lib wave 1 before ext wave 2
        $this->assertContains(1, $waves);
        $this->assertContains(2, $waves);
        $this->assertLessThanOrEqual($waves[array_key_last($waves)], 2);
        // Sorted by wave ascending
        $sorted = $waves;
        sort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $waves);
        $this->removeTree($tmp);
    }

    public function testChunkPlanMaxFilesSplitsOversizedBuckets(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-gen0-chunk-plan.php';
        $tmp = sys_get_temp_dir().'/phpc-chunk-plan-max-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/entries', 0755, true);
        $planPath = $tmp.'/plan.json';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
            .' --lib=JIT/Builtin'
            .' --max-files=5'
            .' --entries-dir='.escapeshellarg($tmp.'/entries')
            .' --plan-out='.escapeshellarg($planPath);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertSame(5, $plan['max_files']);
        $this->assertGreaterThan(1, $plan['chunk_count']);
        foreach ($plan['chunks'] as $chunk) {
            $this->assertLessThanOrEqual(5, (int) $chunk['file_count']);
            $this->assertArrayHasKey('byte_count', $chunk);
            $this->assertGreaterThan(0, (int) $chunk['byte_count']);
        }
        $this->removeTree($tmp);
    }

    public function testChunkPlanMaxBytesSplitsHubRequires(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-gen0-chunk-plan.php';
        $requires = $root.'/script/spine-chunk-core-requires.txt';
        $this->assertFileExists($requires);
        $tmp = sys_get_temp_dir().'/phpc-chunk-plan-hub-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/entries', 0755, true);
        $planPath = $tmp.'/plan.json';
        // hub-core is 33 files / ~850KB; 120KB budget + largest-first packing
        // isolates Runtime.php / CompilerVersion.php / Variable.php as singleton hubs (#36387).
        // SPINE_CHUNK demotes Runtime + PHPCompiler\VM\* method bodies so NestedJIT emits.
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
            .' --requires='.escapeshellarg($requires)
            .' --max-bytes=120000'
            .' --entries-dir='.escapeshellarg($tmp.'/entries')
            .' --plan-out='.escapeshellarg($planPath);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertSame(120000, $plan['max_bytes']);
        // --max-bytes alone applies the tiny-file pack default (#36387).
        $this->assertSame(24, $plan['max_files']);
        $this->assertGreaterThan(1, $plan['chunk_count']);
        $hubs = 0;
        $runtimeAlone = false;
        foreach ($plan['chunks'] as $chunk) {
            $this->assertSame('hub', $chunk['kind']);
            $this->assertSame(0, (int) $chunk['wave']);
            // Single oversized files (CompilerVersion.php ~181KB) keep their own batch.
            if ((int) $chunk['file_count'] > 1) {
                $this->assertLessThanOrEqual(120000, (int) $chunk['byte_count']);
                $this->assertLessThanOrEqual(24, (int) $chunk['file_count']);
            }
            $body = (string) file_get_contents($chunk['entry']);
            if (str_contains($body, 'lib/Runtime.php')) {
                $this->assertSame(
                    1,
                    (int) $chunk['file_count'],
                    'Runtime.php must be a singleton hub under --max-bytes=120000'
                );
                $runtimeAlone = true;
            }
            ++$hubs;
        }
        $this->assertGreaterThan(3, $hubs);
        $this->assertTrue($runtimeAlone, 'expected a Runtime.php singleton hub');
        $this->removeTree($tmp);
    }

    public function testChunkPlanMaxBytesDefaultsMaxFilesForTinyVmPacks(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-gen0-chunk-plan.php';
        $tmp = sys_get_temp_dir().'/phpc-chunk-plan-vm-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/entries', 0755, true);
        $planPath = $tmp.'/plan.json';
        // lib/VM Builtin files are ~1–2KB; --max-bytes=120000 alone previously packed
        // 70–100 into one TU and OOM-killed under 8g (rc=137) even with SPINE_CHUNK demote.
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
            .' --lib=VM'
            .' --max-bytes=120000'
            .' --entries-dir='.escapeshellarg($tmp.'/entries')
            .' --plan-out='.escapeshellarg($planPath);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertSame(120000, $plan['max_bytes']);
        $this->assertSame(24, $plan['max_files']);
        $this->assertGreaterThan(30, $plan['chunk_count']);
        foreach ($plan['chunks'] as $chunk) {
            $this->assertSame('lib', $chunk['kind']);
            if ((int) $chunk['file_count'] > 1) {
                $this->assertLessThanOrEqual(24, (int) $chunk['file_count']);
                $this->assertLessThanOrEqual(120000, (int) $chunk['byte_count']);
            }
        }
        $this->removeTree($tmp);
    }

    public function testChunkPlanSpineStrategyProducesPartitions(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-gen0-chunk-plan.php';
        $tmp = sys_get_temp_dir().'/phpc-chunk-plan-spine-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/entries', 0755, true);
        $planPath = $tmp.'/plan.json';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
            .' --spine --strategy=dir --max-files=200'
            .' --entries-dir='.escapeshellarg($tmp.'/entries')
            .' --plan-out='.escapeshellarg($planPath);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertSame('dir', $plan['strategy']);
        $this->assertGreaterThan(20, $plan['chunk_count']);
        $spine = 0;
        foreach ($plan['chunks'] as $chunk) {
            if (($chunk['kind'] ?? '') === 'spine') {
                ++$spine;
                $this->assertSame(2, (int) $chunk['wave']);
                $this->assertLessThanOrEqual(200, (int) $chunk['file_count']);
            }
        }
        $this->assertGreaterThan(20, $spine);
        $this->removeTree($tmp);
    }

    public function testChunksOrchestratorDefaultsAndEnvContract(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/bootstrap-gen0-chunks.sh');
        $this->assertStringContainsString('bootstrap-gen0-chunk-plan.php', $script);
        $this->assertStringContainsString('bootstrap-gen0-chunk-emit.sh', $script);
        $this->assertStringContainsString('CHUNK_JOBS', $script);
        $this->assertStringContainsString('summary.json', $script);
        $this->assertStringContainsString('wall_seconds', $script);
        $this->assertStringContainsString('fresh receipt — skip', $script);
        $this->assertStringContainsString('--micro', $script);
        $this->assertStringContainsString('--lib=', $script);
        $this->assertStringContainsString('--max-files', $script);
        $this->assertStringContainsString('--max-bytes', $script);
        $this->assertStringContainsString('wave_barrier', $script);
        $this->assertStringContainsString('CHUNK_WAVE_BARRIER', $script);
        $this->assertStringContainsString('CHUNK_LINK_AFTER', $script);
        $this->assertMatchesRegularExpression('/nproc - 2|nproc_n - 2/', $script);
    }

    public function testChunkEmitReceiptIncludesObjectOnlyFlag(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/bootstrap-gen0-chunk-emit.sh');
        $this->assertStringContainsString('object_only', $script);
        $this->assertStringContainsString('lowering_source_fingerprint', $script);
        $this->assertStringContainsString('peer manifests', $script);
        $this->assertStringContainsString('PHP_COMPILER_EXTERNAL_METHOD_MANIFEST', $script);
        $this->assertStringContainsString('CHUNK_PEER_MANIFESTS', $script);
        $this->assertStringContainsString('*.manifest.json', $script);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
