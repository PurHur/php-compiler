<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: setAttributeNode(null)/setAttributeNodeNS(null)/removeAttributeNode(null) TypeError (#33753).
 *
 * php-src: ext/dom/element.c / php_dom.stub.php DOMAttr $attr
 *
 * @group llvm
 * @group aot
 */
final class DomSetAttrNodeNull33753AotTest extends TestCase
{
    private const EXPECTED =
        "set=TypeError:DOMElement::setAttributeNode(): Argument #1 (\$attr) must be of type DOMAttr, null given\n".
        "setns=TypeError:DOMElement::setAttributeNodeNS(): Argument #1 (\$attr) must be of type DOMAttr, null given\n".
        "rm=TypeError:DOMElement::removeAttributeNode(): Argument #1 (\$attr) must be of type DOMAttr, null given\n".
        "set_lit=TypeError:DOMElement::setAttributeNode(): Argument #1 (\$attr) must be of type DOMAttr, null given\n";

    public function testAotSetAttributeNodeNullTypeError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33753_dom_setattrnode_null_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_setattrnode_null_33753_'.getmypid().'.bin';
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
