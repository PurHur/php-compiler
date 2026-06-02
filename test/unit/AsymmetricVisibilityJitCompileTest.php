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
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — asymmetric visibility JIT compile test needs LLVM (#4020)');
        }
    }

    public function testAsymmetricVisibilityModuleVerify(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class Demo {
    public private(set) string $name = 'x';
}
$d = new Demo();
echo $d->name, "\n";
$d->name = 'z';
PHP,
            'asymmetric_visibility_compile.php'
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
