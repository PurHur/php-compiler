<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getElementsByTagName on an importNode destination must not count the source
 * document's loadXML literal (#34630).
 *
 * php-src: ext/dom/nodelist.c / document.c — live NodeList after xmlDocCopyNode
 * Peer: saveXML compileTimeXmlFor (#33697); live tag list (#33659 / #34590).
 *
 * @group llvm
 * @group aot
 */
final class DomImportGetElementsLength34630AotTest extends TestCase
{
    private const REPRO = __DIR__.'/../repro/issue_34630_import_getelements_length.php';

    public function testImportGetElementsLengthVm(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(self::REPRO);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34630_import_getelements_length.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "before=0\nafter=1\nitem0=c\nitem1=null\nxml=<root><n><c>t</c></n></root>\n",
            $out
        );
    }

    public function testImportGetElementsLengthAot(): void
    {
        $this->assertAotMatchesZend(self::REPRO, 'phpc_dom_imp_gebt_34630_');
    }

    private function assertAotMatchesZend(string $src, string $binPrefix): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/'.$binPrefix.getmypid().'.bin';

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";

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
            $this->assertSame($zend, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
