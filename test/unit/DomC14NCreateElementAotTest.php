<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: createElement + setAttribute + appendChild → C14N / C14NFile (#32964 / #32973).
 *
 * LiveSlots elements are not in NestedJIT DomRegistry; fold via compile-time tag+attrs.
 *
 * @see php-src ext/dom/node.c PHP_METHOD(DOMNode, C14N) / C14NFile
 *
 * @group llvm
 * @group aot
 */
final class DomC14NCreateElementAotTest extends TestCase
{
    private const EXPECTED = "ok bytes=13\n";

    public function testVmCreateElementC14N(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32964_dom_c14n_createelement_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32964_dom_c14n_createelement_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotCreateElementC14N(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32964_dom_c14n_createelement_aot.php';
        $bin = sys_get_temp_dir().'/phpc_c14n_ce_32964_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($compile.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
    }
}
