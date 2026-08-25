<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ParentNode::append DOMNode|string scalars TypeError like Zend (#34729).
 *
 * @see php-src ext/dom/php_dom.stub.php append(DOMNode|string ...$nodes)
 * @see php-src ext/dom/parentnode.c
 *
 * @group llvm
 * @group aot
 */
final class DomParentNodeScalarTypeErrorAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
append_int=TypeError:DOMElement::append(): Argument #1 must be of type DOMNode|string, int given
append_bool=TypeError:DOMElement::append(): Argument #1 must be of type DOMNode|string, bool given
append_float=TypeError:DOMElement::append(): Argument #1 must be of type DOMNode|string, float given
append_array=TypeError:DOMElement::append(): Argument #1 must be of type DOMNode|string, array given
append_null=TypeError:DOMElement::append(): Argument #1 must be of type DOMNode|string, null given
append_str=ok:text
TXT;

    public function testVmParentNodeScalarTypeError(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_parentnode_scalar_typeerror_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_parentnode_scalar_typeerror_aot.php'));
        $out = (string) ob_get_clean();
        foreach (['append_int=TypeError:', 'append_bool=TypeError:', 'append_float=TypeError:',
            'append_array=TypeError:', 'append_null=TypeError:', 'append_str=ok:text'] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
    }

    public function testAotParentNodeScalarTypeError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_parentnode_scalar_typeerror_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_pn_scalar_'.getmypid().'.bin';
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
