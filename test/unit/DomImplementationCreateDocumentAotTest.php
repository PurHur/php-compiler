<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMImplementation::createDocument documentElement (#32531).
 *
 * @see php-src ext/dom/php_dom.c PHP_METHOD(DOMImplementation, createDocument)
 *
 * @group llvm
 * @group aot
 */
final class DomImplementationCreateDocumentAotTest extends TestCase
{
    private const EXPECTED = "root|ex:root|http://example.com/ns\n";

    public function testVmCreateDocument(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_implementation_createdocument_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_implementation_createdocument_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotCreateDocument(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_implementation_createdocument_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_createdoc_'.getmypid().'.bin';
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

    public function testAotAllowlistIncludesCreateDocument(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString("'domimplementation::createdocument'", $src);
        $this->assertStringContainsString('DomImplementationCreateDocument', $src);
    }
}
