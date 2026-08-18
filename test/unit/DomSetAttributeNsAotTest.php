<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMElement::setAttributeNS xmlSetNsProp (#32398).
 *
 * @see php-src ext/dom/element.c PHP_METHOD(DOMElement, setAttributeNS)
 *
 * @group llvm
 * @group aot
 */
final class DomSetAttributeNsAotTest extends TestCase
{
    private const EXPECTED = "1\n1\n";

    public function testVmSetAttributeNs(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_setattrns_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_setattrns_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSetAttributeNs(): void
    {
        $this->assertSame(self::EXPECTED, $this->compileAndRun('issue_dom_setattrns_aot.php'));
    }

    public function testVmRemoveAttributeNs(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_removeattrns_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_removeattrns_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("0\n\n", $out);
    }

    public function testAotAllowlistIncludesSetAttributeNs(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString("'domelement::setattributens'", $src);
        $this->assertStringContainsString('DomElementSetAttributeNS', $src);
        $this->assertStringContainsString("'domelement::hasattributens'", $src);
        $this->assertStringContainsString("'domelement::removeattributens'", $src);
    }

    private function compileAndRun(string $reproBasename): string
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproBasename;
        $bin = sys_get_temp_dir().'/phpc_issue_32398_'.getmypid().'_'.md5($reproBasename).'.bin';
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
