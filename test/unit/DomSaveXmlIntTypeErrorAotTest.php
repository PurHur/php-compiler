<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMDocument::saveXML/saveHTML reject int/string arg #1 (#31396).
 *
 * @see php-src ext/dom/php_dom.stub.php ?DOMNode $node
 *
 * @group llvm
 * @group aot
 */
final class DomSaveXmlIntTypeErrorAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
saveXML_int=TypeError:DOMDocument::saveXML(): Argument #1 ($node) must be of type ?DOMNode, int given
saveHTML_int=TypeError:DOMDocument::saveHTML(): Argument #1 ($node) must be of type ?DOMNode, int given
saveXML_string=TypeError:DOMDocument::saveXML(): Argument #1 ($node) must be of type ?DOMNode, string given
TXT;

    public function testVmSaveXmlIntTypeError(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/aot_dom_savexml_int_typeerror.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_dom_savexml_int_typeerror.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED."\n", $out);
    }

    public function testAotSaveXmlIntTypeError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_dom_savexml_int_typeerror.php';
        $bin = sys_get_temp_dir().'/phpc_dom_savexml_int_'.getmypid().'.bin';
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
