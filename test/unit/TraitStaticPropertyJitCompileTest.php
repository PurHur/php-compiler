<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for trait static property merge (#4670).
 *
 * php-src: Zend/zend_traits.c — zend_traits_copy_statics()
 *
 * @group llvm
 */
final class TraitStaticPropertyJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — trait static property JIT compile test needs LLVM (#4670)');
        }
    }

    public function testTraitStaticPropertyMergeModuleVerify(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
trait Counter {
    public static $n = 0;
}
class A { use Counter; }
class B { use Counter; }
echo A::$n, " ", B::$n, "\n";
PHP,
            'trait_static_jit_compile.php'
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
