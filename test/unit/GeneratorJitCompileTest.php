<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for generator MCJIT lowering (#3074).
 *
 * @group llvm
 */
final class GeneratorJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — generator JIT compile test needs LLVM (#3074)');
        }
    }

    public function testGeneratorForeachScriptVerifies(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
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
            'generator_jit_compile.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->assertNotEmpty(
            $context->generatorCreators,
            'expected generator creator map: '.json_encode(array_keys($context->generatorCreators))
        );
        $this->assertArrayHasKey(
            'gen',
            $context->generatorCreators,
            'creator keys: '.implode(', ', array_keys($context->generatorCreators))
        );
        $this->addToAssertionCount(1);
    }

    /**
     * LLVM module state is process-global; isolate from the linear-yield compile test.
     *
     * @runInSeparateProcess
     */
    public function testKeyedYieldForeachScriptVerifies(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function gen() {
    yield 'a' => 1;
    yield 'b' => 2;
}
foreach (gen() as $k => $v) {
    echo $k, $v;
}
PHP
            ,
            'generator_jit_keyed.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->assertArrayHasKey('gen', $context->generatorCreators);
        $this->addToAssertionCount(1);
    }

    /**
     * @runInSeparateProcess
     */
    public function testYieldFromArrayForeachScriptVerifies(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function gen() {
    yield from [1, 2, 3];
}
foreach (gen() as $v) {
    echo $v;
}
PHP
            ,
            'generator_jit_yield_from.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->assertArrayHasKey('gen', $context->generatorCreators);
        $this->addToAssertionCount(1);
    }

    /**
     * @runInSeparateProcess
     */
    public function testYieldFromMixedForeachScriptVerifies(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function gen() {
    yield 1;
    yield from [2, 3];
    yield 4;
}
foreach (gen() as $v) {
    echo $v;
}
PHP
            ,
            'generator_jit_yield_mixed.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->assertArrayHasKey('gen', $context->generatorCreators);
        $this->addToAssertionCount(1);
    }

    /**
     * @runInSeparateProcess
     */
    public function testYieldFromGeneratorForeachScriptVerifies(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
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
            'generator_jit_yield_from_generator.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->assertArrayHasKey('inner', $context->generatorCreators);
        $this->assertArrayHasKey('outer', $context->generatorCreators);
        $this->addToAssertionCount(1);
    }

    /**
     * @runInSeparateProcess
     */
    public function testDynamicYieldFromGeneratorForeachScriptVerifies(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function inner() {
    yield 1;
    yield 2;
}
function outer() {
    $g = inner();
    yield from $g;
    yield 3;
}
foreach (outer() as $v) {
    echo $v;
}
PHP
            ,
            'generator_jit_dyn_yield_from.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->assertArrayHasKey('inner', $context->generatorCreators);
        $this->assertArrayHasKey('outer', $context->generatorCreators);
        $this->addToAssertionCount(1);
    }

    /**
     * Resume cases compile prefix opcodes between yield points (assign before yield).
     *
     * @runInSeparateProcess
     */
    public function testComputedYieldPrefixForeachScriptVerifies(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function gen() {
    $x = 5;
    yield $x;
    $x = 10;
    yield $x;
}
foreach (gen() as $v) {
    echo $v;
}
PHP
            ,
            'generator_jit_computed_yield.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->assertArrayHasKey('gen', $context->generatorCreators);
        $this->addToAssertionCount(1);
    }
}

