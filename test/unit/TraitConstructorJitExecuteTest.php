<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for trait-merged promoted __construct (#4939, Zend/zend_traits.c).
 *
 * Uses bin/jit.php subprocess (in-process Runtime::jit() preloads libLLVM and segfaults, #98).
 *
 * @group llvm
 */
final class TraitConstructorJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — trait constructor JIT execute needs LLVM (#4939)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4939)');
        }
    }

    public function testTraitPromotedConstructorViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
trait HasX {
    public function __construct(public int $x) {}
}
class C {
    use HasX;
}
$c = new C(3);
echo $c->x, "\n";
PHP
            ,
            "3\n");
    }

    public function testTraitConstructorNoLongerForcesVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
trait HasX {
    public function __construct(public int $x) {}
}
class C {
    use HasX;
}
$c = new C(3);
echo $c->x, "\n";
PHP
            ,
            'trait_ctor_promotion.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsTraitConstructorOpcodes($block));
        $this->assertFalse(Block::containsEmptyTraitBodyMcjitDeferral($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'trait_ctor_promotion.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));

        $jit = realpath($this->repoRoot.'/bin/jit.php');
        $this->assertNotFalse($jit);
        $varDir = $this->repoRoot.'/var';
        if (!is_dir($varDir) && !mkdir($varDir, 0775, true) && !is_dir($varDir)) {
            $this->fail('Could not create var/ for trait constructor JIT execute script');
        }
        $script = $varDir.'/trait_ctor_jit_execute_'.getmypid().'_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($script, $code);
        try {
            $env = $this->llvmProcessEnv();
            $out = $this->runScript(
                array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $jit, $script]),
                $env
            );
            $this->assertSame(0, $out['exit'], 'JIT: '.$out['combined']);
            $this->assertSame($expected, $out['stdout']);
        } finally {
            @unlink($script);
        }
    }

    /** @param list<string> $cmd */
    private function runScript(array $cmd, array $env): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'exit' => $exit,
            'stdout' => false !== $stdout ? $stdout : '',
            'combined' => trim((false !== $stdout ? $stdout : '').(false !== $stderr ? $stderr : '')),
        ];
    }

    private function jitRuntimeProbeGreen(): bool
    {
        $probe = $this->repoRoot.'/script/jit-runtime-probe.php';
        $cmd = sprintf(
            'bash -lc %s',
            escapeshellarg('source '.escapeshellarg($this->repoRoot.'/script/php-env.sh')
                .' && '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($probe))
        );
        exec($cmd, $out, $code);

        return 0 === $code && str_contains(implode("\n", $out), 'jit-runtime-probe OK');
    }

    /** @return array<string, string> */
    private function llvmProcessEnv(): array
    {
        $env = $_ENV;
        foreach ($_SERVER as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        return $env;
    }
}
