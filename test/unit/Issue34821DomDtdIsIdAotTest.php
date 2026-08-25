<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadXML DTD ATTLIST ID / xml:id → Attr::isId() true like Zend (#34821).
 *
 * php-src: ext/dom/attr.c dom_attr_is_id_read (atype == XML_ATTRIBUTE_ID)
 *
 * @group llvm
 * @group aot
 */
final class Issue34821DomDtdIsIdAotTest extends TestCase
{
    private const EXPECTED =
        "dtd_chain=true\n".
        "class_chain=false\n".
        "dtd_assigned=true\n".
        "byId=c\n".
        "xmlid_assigned=true\n".
        "xmlid_byId=c\n";

    public function testVmDtdAndXmlIdIsId(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34821_dom_dtd_isid_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34821_dom_dtd_isid_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDtdAndXmlIdIsId(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34821_dom_dtd_isid_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_dtd_isid_34821_'.getmypid().'.bin';
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
