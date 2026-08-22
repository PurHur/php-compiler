<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: setIdAttribute on a later sibling registers that id, not first loadXML id= (#33957).
 *
 * @see php-src ext/dom/element.c PHP_METHOD(DOMElement, setIdAttribute) → xmlAddID
 * @see php-src ext/dom/php_dom.c dom_document_get_element_by_id
 *
 * @group llvm
 * @group aot
 */
final class Issue33957SetIdAttributeLaterSiblingAotTest extends TestCase
{
    private const EXPECTED = "y=b\nx=null\n";

    public function testVmSetIdAttributeLaterSibling(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_33957_setidattribute_later_sibling_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33957_setidattribute_later_sibling_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSetIdAttributeLaterSibling(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33957_setidattribute_later_sibling_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33957_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($bin);
        }
    }
}
