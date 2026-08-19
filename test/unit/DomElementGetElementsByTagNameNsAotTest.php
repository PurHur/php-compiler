<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMElement::getElementsByTagNameNS descendant NodeList (#32511).
 *
 * @see php-src ext/dom/element.c PHP_METHOD(DOMElement, getElementsByTagNameNS)
 *
 * @group llvm
 * @group aot
 */
final class DomElementGetElementsByTagNameNsAotTest extends TestCase
{
    private const EXPECTED = "len=2|i0=a|i1=c\n";

    public function testVmElementGetElementsByTagNameNs(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_element_gebtns_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_element_gebtns_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotElementGetElementsByTagNameNs(): void
    {
        $this->assertSame(self::EXPECTED, $this->compileAndRun());
    }

    public function testAotAllowlistIncludesElementGetElementsByTagNameNs(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString("'domelement::getelementsbytagnamens'", $src);
        $this->assertStringContainsString('DomElementGetElementsByTagNameNS', $src);
    }

    private function compileAndRun(): string
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_element_gebtns_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_el_gebtns_'.getmypid().'.bin';
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
