<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ChildNode after/before/replaceWith(null) TypeError (#33746).
 *
 * php-src: ext/dom/childnode.c / php_dom.stub.php ChildNode DOMNode|string
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodeVarNull33746AotTest extends TestCase
{
    private const EXPECTED =
        "after=TypeError:DOMElement::after(): Argument #1 must be of type DOMNode|string, null given\n".
        "before=TypeError:DOMElement::before(): Argument #1 must be of type DOMNode|string, null given\n".
        "replaceWith=TypeError:DOMElement::replaceWith(): Argument #1 must be of type DOMNode|string, null given\n".
        "id=TypeError:DOMElement::after(): Argument #1 must be of type DOMNode|string, null given\n";

    public function testAotChildNodeNullTypeError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33746_dom_childnode_null_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_childnode_null_33746_'.getmypid().'.bin';
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
