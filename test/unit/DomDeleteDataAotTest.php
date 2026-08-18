<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMCharacterData::deleteData xmlUTF8Strsub (#32389).
 *
 * @see php-src ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, deleteData)
 *
 * @group llvm
 * @group aot
 */
final class DomDeleteDataAotTest extends TestCase
{
    private const EXPECTED = "ad\n";

    public function testVmDeleteData(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_deletedata_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_deletedata_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDeleteData(): void
    {
        $this->assertSame(self::EXPECTED, $this->compileAndRun('issue_dom_deletedata_aot.php'));
    }

    public function testVmDeleteDataIndexSizeError(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_deletedata_index_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_deletedata_index_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("Index Size Error\nIndex Size Error\nab\n", $out);
    }

    public function testAotAllowlistIncludesDeleteData(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString("'domtext::deletedata'", $src);
        $this->assertStringContainsString('DomCharacterDataDeleteData', $src);
    }

    private function compileAndRun(string $reproBasename): string
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproBasename;
        $bin = sys_get_temp_dir().'/phpc_issue_32389_'.getmypid().'_'.md5($reproBasename).'.bin';
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
