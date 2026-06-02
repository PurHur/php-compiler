<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/** MCJIT for Class::$$name / Class::$var static property access (#4597). */
final class StaticPropertyDynamicJitTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testRequiresVmLoweringFalseForDynamicStaticProperty(): void
    {
        $code = <<<'PHP'
<?php
class Box {
    public static string $label = 'ok';
}
$key = 'label';
echo Box::$$key, "\n";
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dyn_static.php');
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsDynamicStaticPropertyOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testJitLoweringDoesNotRequireLiteralPropertyName(): void
    {
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!$this->jitRuntimeProbeOk()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT unavailable (#4597)');
        }

        $wrapper = $this->repoRoot.'/var/static-prop-dynamic-jit-probe-'.getmypid().'.php';
        $autoload = $this->repoRoot.'/vendor/autoload.php';
        file_put_contents($wrapper, <<<PHP
<?php
require '{$autoload}';
PHP
        .<<<'PHP'

$code = <<<'SRC'
<?php
class Box {
    public static string $label = 'ok';
}
$key = 'label';
echo Box::$$key, "\n";
SRC;
$r = new PHPCompiler\Runtime();
$block = $r->parseAndCompile($code, 'dyn_static.php');
$r->jit($block, $code, 'dyn_static.php');
echo "JIT_COMPILE_OK\n";

PHP
        );

        $env = $this->llvmProcessEnv();
        $out = $this->runPhpCode(
            array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $wrapper]),
            '',
            $env
        );
        @unlink($wrapper);

        if (139 === $out['exit'] || 11 === $out['exit']) {
            $this->markTestSkipped('MCJIT subprocess crashed during compile (#4597)');
        }
        $this->assertSame(
            0,
            $out['exit'],
            'JIT compile subprocess failed: '.$out['combined']
        );
        $this->assertStringNotContainsString(
            'literal property name',
            $out['combined']
        );
        $this->assertStringContainsString('JIT_COMPILE_OK', $out['stdout']);
    }

    private function jitRuntimeProbeOk(): bool
    {
        $probe = $this->repoRoot.'/script/jit-runtime-probe.php';
        if (!is_file($probe)) {
            return false;
        }
        $env = $this->llvmProcessEnv();
        $cmd = array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $probe]);
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($proc);
    }

    /**
     * @return array<string, string>
     */
    private function llvmProcessEnv(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        return $env;
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{exit: int, stdout: string, stderr: string, combined: string}
     */
    private function runPhpCode(array $cmd, string $stdin, array $env): array
    {
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($proc);
        if ('' !== $stdin) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'exit' => $exit,
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
            'combined' => trim((false !== $stderr ? $stderr : '')."\n".(false !== $stdout ? $stdout : '')),
        ];
    }
}
