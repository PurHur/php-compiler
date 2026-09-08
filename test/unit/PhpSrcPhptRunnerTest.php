<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Harness self-tests for script/php-src-phpt.php (#36381).
 */
final class PhpSrcPhptRunnerTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        $this->repo = dirname(__DIR__, 2);
        require_once $this->repo . '/script/php-src/php-src-phpt.php';
    }

    public function testListSampleCorpusIsNonEmpty(): void
    {
        $root = $this->repo . '/test/php-src/corpus/sample';
        $names = \PhpSrcPhptRunner::listCases($root);
        $this->assertGreaterThanOrEqual(5, count($names), 'sample corpus must stay non-empty');
        $this->assertContains('001_echo', $names);
        $this->assertContains('003_skipif_skip', $names);
    }

    public function testShardIsHashStableAndCoversAll(): void
    {
        $names = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
        $seen = [];
        for ($shard = 0; $shard < 4; $shard++) {
            foreach (\PhpSrcPhptRunner::shardCases($names, 4, $shard) as $n) {
                $seen[] = $n;
            }
        }
        sort($seen);
        $this->assertSame($names, $seen);
        // Same input → same shard forever.
        $this->assertSame(
            \PhpSrcPhptRunner::shardCases($names, 4, 1),
            \PhpSrcPhptRunner::shardCases($names, 4, 1)
        );
    }

    public function testEmptyCasesRootFailsHonestlyViaCli(): void
    {
        $empty = sys_get_temp_dir() . '/php-src-phpt-empty-' . getmypid();
        @mkdir($empty, 0755, true);
        $cmd = [
            PHP_BINARY,
            $this->repo . '/script/php-src/php-src-phpt.php',
            '--php-src=' . dirname($empty),
            '--dirs=' . basename($empty),
            '--backend=zend',
            '--list',
        ];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->repo);
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        @rmdir($empty);
        $this->assertSame(2, $rc, 'empty corpus must exit 2, not 0');
        $this->assertStringContainsString('zero .phpt', (string) $stderr);
        $this->assertSame('', trim((string) $stdout));
    }

    public function testBuildSummaryIncludesPassPct(): void
    {
        $summary = \php_src_phpt_build_summary([
            'executed' => 4,
            'pass' => 4,
            'fail' => 0,
            'skip' => 1,
            'bork' => 0,
            'backend' => 'vm',
            'label' => 'corpus/sample',
        ], []);
        $this->assertSame(100.0, $summary['pass_pct']);
        $this->assertSame(36381, $summary['issue']);
        $this->assertSame([], $summary['top_failures']);
    }

    public function testJsonDiffEmitsMachineSummaryOnStdout(): void
    {
        $cmd = [
            PHP_BINARY,
            $this->repo . '/script/php-src/php-src-phpt.php',
            '--corpus=sample',
            '--backend=vm',
            '--diff',
            '--json',
        ];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->repo, [
            'PHP_COMPILER_MEMORY_LIMIT' => '512M',
        ]);
        $this->assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        $this->assertSame(0, $rc, $stderr);
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, 'stdout must be JSON only; got: ' . substr($stdout, 0, 200));
        $this->assertSame('ok', $decoded['diff'] ?? null);
        $this->assertEqualsWithDelta(100.0, (float) ($decoded['pass_pct'] ?? 0), 0.01);
        $this->assertSame(4, $decoded['pass'] ?? null);
        $this->assertStringContainsString('DIFF OK', $stderr);
    }
}
