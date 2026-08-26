<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadXML ProcessingInstruction becomes a live firstChild (#34952).
 *
 * @see php-src ext/dom/processinginstruction.c
 * @see php-src ext/dom/document.c loadXML / libxml XML_PI_NODE
 *
 * @group llvm
 * @group aot
 */
final class Issue34952LoadXmlPiFirstChildAotTest extends TestCase
{
    private const EXPECTED = "len=1\nname=pi\ndata=data\ntext=\nxml=<r><?pi data?></r>\n";

    public function testVmLoadXmlPiFirstChild(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34952_loadxml_pi_firstchild_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34952_loadxml_pi_firstchild_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotLoadXmlPiFirstChild(): void
    {
        $this->assertSame(self::EXPECTED, $this->compileAndRun());
    }

    private function compileAndRun(): string
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34952_loadxml_pi_firstchild_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34952_'.getmypid().'.bin';
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

            return implode("\n", $runOut)."\n";
        } finally {
            @unlink($bin);
        }
    }
}
