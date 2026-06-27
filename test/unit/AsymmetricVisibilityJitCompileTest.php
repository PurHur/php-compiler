<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for asymmetric set-visibility JIT guards (#4020).
 *
 * php-src: Zend/zend_object_handlers.c — zend_check_property_access write mask
 *
 * @group llvm
 */
final class AsymmetricVisibilityJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        if (!CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility disabled on reference profile (#12508)');
        }
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — asymmetric visibility JIT compile test needs LLVM (#4020)');
        }
    }

    public function testAsymmetricVisibilityModuleVerify(): void
    {
        $this->assertModuleVerifies(<<<'PHP'
<?php
class Demo {
    private(set) string $name = 'x';
}
$d = new Demo();
echo $d->name, "\n";
$d->name = 'z';
PHP);
    }

    /** In-class writes on private(set) properties must compile (#4639). */
    public function testAsymmetricVisibilityInClassWriteModuleVerify(): void
    {
        $this->assertModuleVerifies(<<<'PHP'
<?php
class Demo {
    private(set) string $name = 'x';
    public function mutate(): void { $this->name = 'y'; }
}
$d = new Demo();
$d->mutate();
echo $d->name, "\n";
PHP);
    }

    private function assertModuleVerifies(string $code): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'asymmetric_visibility_compile.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
