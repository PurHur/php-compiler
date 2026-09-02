<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** bench-gate performance regression gate (#36196). */
final class BenchGateTest extends TestCase
{
    public function testBenchGateScriptsExist(): void
    {
        $root = dirname(__DIR__, 2);
        $sh = $root.'/script/bench-gate.sh';
        $php = $root.'/script/bench-gate.php';
        $this->assertFileExists($sh);
        $this->assertFileExists($php);
        $this->assertTrue(is_executable($sh));
        $body = (string) file_get_contents($sh);
        $this->assertStringContainsString('docker-exec.sh', $body);
        $this->assertStringContainsString('bench-gate.php', $body);
        $this->assertStringContainsString('--update', $body);
    }

    public function testBaselineJsonHasHeadlineCases(): void
    {
        $path = dirname(__DIR__, 2).'/benchmarks/BASELINE.json';
        $this->assertFileExists($path);
        $doc = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($doc);
        $this->assertArrayHasKey('cases', $doc);
        foreach (['fibo(30)', 'simple', 'mandelbrot', 'Ack(3,8)'] as $name) {
            $this->assertArrayHasKey($name, $doc['cases'], $name);
            $this->assertArrayHasKey('ratio_aot_over_zend', $doc['cases'][$name]);
            $this->assertArrayHasKey('ir_lines', $doc['cases'][$name]);
        }
        $this->assertSame(30, $doc['ratio_tolerance_percent'] ?? null);
    }

    public function testCompilerGateWorkflowRunsBenchGate(): void
    {
        $wf = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/compiler-gate.yml');
        $this->assertStringContainsString('script/bench-gate.sh', $wf);
        $this->assertStringContainsString('#36196', $wf);
    }

    public function testBenchPhpRequiresZendRuntime(): void
    {
        $bench = (string) file_get_contents(dirname(__DIR__, 2).'/script/bench.php');
        $this->assertStringContainsString('Specify at least one Zend runtime via PHP_X_Y', $bench);
        $this->assertStringNotContainsString('exit(0)', $bench);
    }
}
