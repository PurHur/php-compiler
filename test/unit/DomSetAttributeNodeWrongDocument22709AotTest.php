<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: setAttributeNode/NS foreign Attr → Wrong Document Error (#22709).
 *
 * @see php-src ext/dom/element.c dom_element_set_attribute_node
 *
 * @group llvm
 * @group aot
 */
final class DomSetAttributeNodeWrongDocument22709AotTest extends TestCase
{
    private const REPRO = __DIR__.'/../repro/dom_element_setattrnode_wrong_document.php';

    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(self::REPRO);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_element_setattrnode_wrong_document.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('code=4 msg=Wrong Document Error', $out);
        $this->assertStringContainsString('NS code=4 msg=Wrong Document Error', $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_setattrnode_wrong_doc_22709_'.getmypid().'.bin';

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::REPRO).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";

        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg(self::REPRO).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zend, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
