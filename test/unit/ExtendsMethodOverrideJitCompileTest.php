<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Call\Native;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * JIT compile: extends method override resolves child proxy, not ExternalMethod stub (#101).
 *
 * @group llvm
 */
final class ExtendsMethodOverrideJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — extends JIT compile test needs LLVM (#101)');
        }
    }

    public function testChildOverrideMethodProxyIsNative(): void
    {
        $code = <<<'PHP'
<?php
class B {
    public function f(): int {
        return 1;
    }
}
class C extends B {
    public function f(): int {
        return 2;
    }
}
echo (new C())->f();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'extends_override.php');
        $runtime->jitCompileBlock($block);
        $ctx = $runtime->loadJitContext();
        $this->assertArrayHasKey('b::f', $ctx->functionProxies);
        $this->assertArrayHasKey('c::f', $ctx->functionProxies);
        $this->assertInstanceOf(Native::class, $ctx->functionProxies['b::f']);
        $this->assertInstanceOf(Native::class, $ctx->functionProxies['c::f']);
        $this->assertNotInstanceOf(ExternalMethod::class, $ctx->functionProxies['c::f']);
        $verify = new \ReflectionMethod($ctx, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($ctx);
        $jit = $runtime->loadJit();
        $ref = new \ReflectionMethod($jit, 'resolveJitInstanceMethodProxyName');
        $ref->setAccessible(true);
        $this->assertSame('c::f', $ref->invoke($jit, 'c', 'f'));
    }
}
