<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for file-level const array literals (#4941, Zend/zend_constants.c).
 *
 * @group llvm
 */
final class GlobalConstArrayJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — global const array JIT execute needs LLVM (#4941)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4941)');
        }
    }

    public function testGlobalConstArrayPhptViaMcjit(): void
    {
        $path = $this->repoRoot.'/test/compliance/cases/language/global_const_array.phpt';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--EXPECT--\s*\n(.*)\s*$/s', $raw, $m)) {
            $this->fail('global_const_array.phpt missing --FILE-- / --EXPECT--');
        }
        $this->assertMcjitOutput($m[1], $m[2]);
    }

    public function testGlobalConstArrayInFunctionViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
const FOO = [1, 2, 3];
function f(): int {
    return FOO[0] + FOO[1];
}
echo f(), "\n";
PHP
            ,
            "3\n");
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsGlobalConstArrayLiteralOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jit($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame($expected, ob_get_clean());
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
}
