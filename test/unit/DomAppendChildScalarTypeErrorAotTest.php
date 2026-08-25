<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMNode::appendChild() scalars TypeError like Zend, not compile LogicException / SIGSEGV (#34716).
 *
 * @see php-src ext/dom/php_dom.stub.php DOMNode::appendChild(DOMNode $node)
 * @see php-src Zend/zend_API.h Z_PARAM_OBJ_OF_CLASS
 *
 * @group llvm
 * @group aot
 */
final class DomAppendChildScalarTypeErrorAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
int=TypeError:DOMNode::appendChild(): Argument #1 ($node) must be of type DOMNode, int given
bool=TypeError:DOMNode::appendChild(): Argument #1 ($node) must be of type DOMNode, bool given
float=TypeError:DOMNode::appendChild(): Argument #1 ($node) must be of type DOMNode, float given
string=TypeError:DOMNode::appendChild(): Argument #1 ($node) must be of type DOMNode, string given
array=TypeError:DOMNode::appendChild(): Argument #1 ($node) must be of type DOMNode, array given
null=TypeError:DOMNode::appendChild(): Argument #1 ($node) must be of type DOMNode, null given
var_null=TypeError:DOMNode::appendChild(): Argument #1 ($node) must be of type DOMNode, null given
ok
TXT;

    public function testVmAppendChildScalarTypeError(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_appendchild_scalar_typeerror_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_appendchild_scalar_typeerror_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED."\n", $out);
    }

    public function testAotAppendChildScalarTypeError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_appendchild_scalar_typeerror_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_ac_scalar_'.getmypid().'.bin';
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
