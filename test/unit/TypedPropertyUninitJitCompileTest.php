<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../LlvmToolchain.php';

/**
 * JIT compile uninitialized typed property read guard (#4569).
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class TypedPropertyUninitJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason . ' — typed property uninit JIT compile test needs LLVM');
        }
    }

    public function testUninitTypedPropertyReadModuleVerify(): void
    {
        $this->assertJitModuleVerifies(
            $this->repoRoot . '/test/compliance/cases/language/typed_property_uninitialized.phpt',
            'typed_property_uninitialized.phpt'
        );
    }

    public function testUninitStaticTypedPropertyReadModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public static int $x;
}
function f(): void {
    echo C::$x, "\n";
}
f();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'static_typed_uninit_jit_pure.php');
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }

    private function assertJitModuleVerifies(string $path, string $label): void
    {
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--EXPECT/s', $contents, $matches)) {
            $this->fail($label . ' FILE section missing');
        }

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($matches[1], $label);
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
