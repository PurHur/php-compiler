<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getAttribute stays per-element after lastChild / getElementById (#34863).
 *
 * @see php-src ext/dom/element.c dom_element_get_attribute / xmlGetProp
 *
 * @group llvm
 * @group aot
 */
final class DomGetAttrSibling34863AotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
onlyA=a
afterB_A=a
afterB_B=b
gebiA=a
gebiB=b
sameA=yes
sameB=yes
TXT;

    public function testVmGetAttrSibling(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34863_dom_getattr_sibling_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34863_dom_getattr_sibling_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED."\n", $out);
    }

    public function testAotGetAttrSibling(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34863_dom_getattr_sibling_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_getattr_34863_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
