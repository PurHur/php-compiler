<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** dl() VM/JIT/AOT smoke (issue #3591, ext/standard/dl.c). */
final class DlBuiltinTest extends TestCase
{
    public function testVmTypeErrorForNonString(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
try {
    dl([]);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
PHP,
            'dl_typeerror.php'
        );
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
        }
        $this->assertSame(
            "TypeError: dl(): Argument #1 (\$extension_filename) must be of type string, array given\n",
            ob_get_clean()
        );
    }

    /**
     * @group llvm
     */
    public function testAotCompileOnlyLowering(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $target = dirname(__DIR__, 2).'/test/fixtures/aot/compile-only/dl_stub.php';
        $this->assertFileExists($target);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile((string) file_get_contents($target), 'dl_stub_jit_compile.php');
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
