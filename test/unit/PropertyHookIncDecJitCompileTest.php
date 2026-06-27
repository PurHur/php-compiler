<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\PropertyHookProfileSkipTrait;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for ++/-- on instance property hooks (#6452, #6309).
 *
 * php-src: Zend/zend_property_hooks.c — inc/dec on hooked properties
 *
 * @group llvm
 */
final class PropertyHookIncDecJitCompileTest extends TestCase
{
    use PropertyHookProfileSkipTrait;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooks();
    }

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — property hook inc/dec JIT compile test needs LLVM (#6452)');
        }
    }

    public function testInstancePropertyHookIncDecJitModuleVerify(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class Counter {
    private int $n = 0;
    public int $count {
        get => $this->n;
        set => $this->n = $value;
    }
}
$c = new Counter();
echo ++$c->count, "\n";
echo $c->count++, "\n";
echo $c->count, "\n";
PHP,
            'property_hook_incdec_jit_compile.php'
        );
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }

    public function testGetOnlyPropertyHookIncDecJitModuleVerify(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class Box {
    private int $n = 0;
    public int $count {
        get => $this->n;
    }
}
$b = new Box();
try {
    $b->count++;
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
PHP,
            'property_hook_get_only_incdec_jit_compile.php'
        );
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
