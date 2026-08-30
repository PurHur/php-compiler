<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: SimpleXMLElement instanceof Traversable leftover of host-tree folds (#35831 / #26863).
 *
 * php-src: ext/simplexml/simplexml.stub.php — Stringable, Countable, RecursiveIterator
 *
 * @group llvm
 * @group aot
 */
final class SimpleXmlInstanceofAotTest extends TestCase
{
    /** Zend 8.2+ — ArrayAccess methods exist but the class does not implement ArrayAccess. */
    private const EXPECTED = "Traversable=1\nIterator=1\nCountable=1\nRecursiveIterator=1\nStringable=1\nArrayAccess=0\nself=1\n";

    public function testVmTraversableAndSelfAreTrue(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/simplexml_instanceof_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'simplexml_instanceof_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('Traversable=1', $out);
        $this->assertStringContainsString('Iterator=1', $out);
        $this->assertStringContainsString('Countable=1', $out);
        $this->assertStringContainsString('RecursiveIterator=1', $out);
        $this->assertStringContainsString('Stringable=1', $out);
        $this->assertStringContainsString('self=1', $out);
    }

    public function testAotInstanceofMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/simplexml_instanceof_aot.php';
        $bin = sys_get_temp_dir().'/phpc_sxe_ia_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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
