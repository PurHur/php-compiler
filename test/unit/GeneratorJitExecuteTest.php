<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for linear generator foreach (#3074).
 *
 * Uses Runtime::jit() directly (script-scope yield still VM-fallbacks in bin/jit.php).
 * Skipped when script/jit-runtime-probe.php fails (#98 / #2114).
 *
 * @group llvm
 */
final class GeneratorJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — generator JIT execute needs LLVM (#3074)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#3074)');
        }
    }

    public function testGeneratorForeachExecutesViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
function gen() {
    yield 1;
    yield 2;
}
foreach (gen() as $v) {
    echo $v;
}
PHP
            ,
            '12');
    }

    public function testYieldFromArrayForeachExecutesViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
function gen() {
    yield from [1, 2, 3];
}
foreach (gen() as $v) {
    echo $v;
}
PHP
            ,
            '123');
    }

    public function testYieldFromGeneratorForeachExecutesViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
function inner() {
    yield 1;
    yield 2;
}
function outer() {
    yield from inner();
    yield 3;
}
foreach (outer() as $v) {
    echo $v;
}
PHP
            ,
            '123');
    }

    public function testGeneratorTryCatchExecutesViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
function gen() {
    try {
        yield 1;
        throw new Exception('boom');
        yield 2;
    } catch (Exception $e) {
        yield 'caught:boom';
    }
}
foreach (gen() as $v) {
    echo $v, "\n";
}
PHP
            ,
            "1\ncaught:boom\n");
    }

    public function testRequiresVmLoweringSkipsNestedGeneratorBodies(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function gen() {
    yield 1;
}
foreach (gen() as $v) {
    echo $v;
}
PHP
            ,
            'generator_scope_probe.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $this->assertFalse(Block::containsGeneratorOpcodesInScriptScope($block));
        $this->assertTrue(Block::containsGeneratorOpcodes($block));
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        $this->assertNotNull($block);
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
