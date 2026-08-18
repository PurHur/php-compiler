<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMText::wholeText on createTextNode stand-in (#32395).
 *
 * @see php-src ext/dom/text.c dom_text_whole_text_read
 *
 * @group llvm
 * @group aot
 */
final class DomWholeTextAotTest extends TestCase
{
    public function testVmWholeText(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_wholetext_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_wholetext_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("ab\n", $out);
    }

    public function testAotWholeText(): void
    {
        $this->assertSame("ab\n", $this->compileAndRun('issue_dom_wholetext_aot.php'));
    }

    public function testAotWholeTextAfterInsertData(): void
    {
        $this->assertSame("abc\n", $this->compileAndRun('issue_dom_wholetext_insertdata_aot.php'));
    }

    private function compileAndRun(string $reproBasename): string
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproBasename;
        $bin = sys_get_temp_dir().'/phpc_issue_32395_'.getmypid().'_'.md5($reproBasename).'.bin';
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
