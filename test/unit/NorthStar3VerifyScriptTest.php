<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * make north-star3-verify / script/north-star3-verify.sh (issue #2360).
 */
final class NorthStar3VerifyScriptTest extends TestCase
{
    public function testNorthStar3VerifyScriptExistsAndPrintsHelp(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/north-star3-verify.sh';
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
        $this->assertStringContainsString('north-star3-verify', $combined);
        $this->assertStringContainsString('008-SelfHostProbe', $combined);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-unit-probe.sh', $combined);
        $this->assertStringContainsString('bootstrap-selfhost-jit-unit-probe.sh', $combined);
        $this->assertStringContainsString('bootstrap-selfhost-vm-unit-probe.sh', $combined);
        $this->assertStringContainsString('bootstrap-selfhost-parser-unit-probe.sh', $combined);
        $this->assertStringContainsString('bootstrap-selfhost-types-unit-probe.sh', $combined);
        $this->assertStringContainsString('BOOTSTRAP_COMPILER_UNIT_PROBE_GATE', $combined);
        $this->assertStringContainsString('BOOTSTRAP_JIT_UNIT_PROBE_GATE', $combined);
        $this->assertStringContainsString('BOOTSTRAP_VM_UNIT_PROBE_GATE', $combined);
        $this->assertStringContainsString('BOOTSTRAP_PARSER_UNIT_PROBE_GATE', $combined);
        $this->assertStringContainsString('BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE', $combined);
        $this->assertStringContainsString('#2430', $combined);
        $this->assertStringContainsString('#2434', $combined);
        $this->assertStringContainsString('#2360', $combined);
        $this->assertStringContainsString('#1492', $combined);
        $this->assertStringContainsString('--require-llvm', $combined);
        $this->assertStringContainsString('NORTH_STAR3_VERIFY_GATE', $combined);
        $this->assertStringContainsString('#2396', $combined);
    }

    public function testNorthStar3VerifyScriptDocumentsSteps(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/north-star3-verify.sh');
        $this->assertStringContainsString('008-SelfHostProbe/example.php', $body);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-unit-probe.sh', $body);
        $this->assertStringContainsString('bootstrap-selfhost-jit-unit-probe.sh', $body);
        $this->assertStringContainsString('bootstrap-selfhost-vm-unit-probe.sh', $body);
        $this->assertStringContainsString('bootstrap-selfhost-parser-unit-probe.sh', $body);
        $this->assertStringContainsString('bootstrap-selfhost-types-unit-probe.sh', $body);
        $this->assertStringContainsString('BOOTSTRAP_COMPILER_UNIT_PROBE_GATE', $body);
        $this->assertStringContainsString('BOOTSTRAP_JIT_UNIT_PROBE_GATE', $body);
        $this->assertStringContainsString('BOOTSTRAP_VM_UNIT_PROBE_GATE', $body);
        $this->assertStringContainsString('BOOTSTRAP_PARSER_UNIT_PROBE_GATE', $body);
        $this->assertStringContainsString('BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE', $body);
        $this->assertStringContainsString('#2430', $body);
        $this->assertStringContainsString('#2434', $body);
        $this->assertStringContainsString('ci_llvm_ready', $body);
        $this->assertStringContainsString('north-star3-verify: OK', $body);
        $this->assertStringContainsString('ns3_run_unit_probe', $body);
    }

    public function testMakefileDeclaresNorthStar3VerifyTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('north-star3-verify:', $makefile);
        $this->assertStringContainsString('script/north-star3-verify.sh', $makefile);
    }
}
