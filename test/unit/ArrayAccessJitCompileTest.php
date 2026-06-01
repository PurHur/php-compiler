<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for ArrayAccess MCJIT lowering (#4012).
 *
 * @group llvm
 */
final class ArrayAccessJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — ArrayAccess JIT compile test needs LLVM (#4012)');
        }
    }

    public function testArrayAccessUserScriptDoesNotRequireVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class Box implements ArrayAccess {
    private array $data = [];
    public function offsetExists($k) { return isset($this->data[$k]); }
    public function offsetGet($k) { return $this->data[$k]; }
    public function offsetSet($k, $v) { $this->data[$k] = $v; }
    public function offsetUnset($k) { unset($this->data[$k]); }
}
$b = new Box();
$b['x'] = 42;
echo $b['x'], "\n";
PHP
            ,
            'array_access_jit_compile.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
