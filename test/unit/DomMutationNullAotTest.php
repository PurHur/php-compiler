<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOM mutation/importNode(null) TypeError, not SIGSEGV (#32558).
 *
 * @see php-src ext/dom/node.c Z_PARAM_OBJ_OF_CLASS
 * @see php-src ext/dom/document.c PHP_METHOD(DOMDocument, importNode)
 *
 * @group llvm
 * @group aot
 */
final class DomMutationNullAotTest extends TestCase
{
    private const EXPECTED = "docAppendChild=DOMNode::appendChild(): Argument #1 (\$node) must be of type DOMNode, null given\n"
        ."appendChild=DOMNode::appendChild(): Argument #1 (\$node) must be of type DOMNode, null given\n"
        ."insertBefore=DOMNode::insertBefore(): Argument #1 (\$node) must be of type DOMNode, null given\n"
        ."replaceChild=DOMNode::replaceChild(): Argument #1 (\$node) must be of type DOMNode, null given\n"
        ."removeChild=DOMNode::removeChild(): Argument #1 (\$child) must be of type DOMNode, null given\n"
        ."importNode=DOMDocument::importNode(): Argument #1 (\$node) must be of type DOMNode, null given\n";

    public function testVmMutationNullTypeError(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_mutation_null_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_mutation_null_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotMutationNullTypeError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_mutation_null_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_mut_null_'.getmypid().'.bin';
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
