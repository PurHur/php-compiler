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
            $this->assertFileExists($chunk['entry']);
            $this->assertStringContainsString('chunk-'.$chunk['chunk_id'], (string) file_get_contents($chunk['entry']));
        }
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
        $this->assertMatchesRegularExpression('/nproc - 2|nproc_n - 2/', $script);
    }

    public function testChunkEmitReceiptIncludesObjectOnlyFlag(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/bootstrap-gen0-chunk-emit.sh');
        $this->assertStringContainsString('object_only', $script);
        $this->assertStringContainsString('lowering_source_fingerprint', $script);
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
