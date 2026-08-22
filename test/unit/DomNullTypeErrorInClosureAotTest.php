<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: outer try/catch must catch DOM null TypeError thrown inside a closure (#33971).
 *
 * @see php-src ext/dom/node.c Z_PARAM_OBJ_OF_CLASS(DOMNode)
 * @see \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort
 *
 * @group llvm
 * @group aot
 */
final class DomNullTypeErrorInClosureAotTest extends TestCase
{
    private const EXPECTED = "TypeError:DOMNode::appendChild(): Argument #1 (\$node) must be of type DOMNode, null given\n";

    public function testVmClosureOuterCatch(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/aot_dom_null_typeerror_in_closure.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_dom_null_typeerror_in_closure.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotClosureOuterCatch(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_dom_null_typeerror_in_closure.php';
        $bin = sys_get_temp_dir().'/phpc_dom_null_closure_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
