<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: insertBefore($node, scalar) TypeError (?DOMNode); var-null ref still appends (#34729).
 *
 * @see php-src ext/dom/php_dom.stub.php insertBefore(DOMNode $node, ?DOMNode $child = null)
 * @see php-src ext/dom/node.c
 *
 * @group llvm
 * @group aot
 */
final class DomInsertBeforeRefScalarTypeErrorAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
ib_int=TypeError:DOMNode::insertBefore(): Argument #2 ($child) must be of type ?DOMNode, int given
ib_array=TypeError:DOMNode::insertBefore(): Argument #2 ($child) must be of type ?DOMNode, array given
ib_str=TypeError:DOMNode::insertBefore(): Argument #2 ($child) must be of type ?DOMNode, string given
ib_var_null=ok
TXT;

    public function testVmInsertBeforeRefScalarTypeError(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_insertbefore_ref_scalar_typeerror_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_insertbefore_ref_scalar_typeerror_aot.php'));
        $out = (string) ob_get_clean();
        foreach (['ib_int=TypeError:', 'ib_array=TypeError:', 'ib_str=TypeError:',
            "ib_var_null=ok\n"] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
    }

    public function testAotInsertBeforeRefScalarTypeError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_insertbefore_ref_scalar_typeerror_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_ib_ref_scalar_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED."\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
