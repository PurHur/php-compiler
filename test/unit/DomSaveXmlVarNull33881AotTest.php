<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMDocument::saveXML($n=null) — no SIGSEGV (#33881).
 *
 * php-src: ext/dom/document.c Z_PARAM_OBJ_OF_CLASS_OR_NULL — null → document dump.
 *
 * @group llvm
 * @group aot
 */
final class DomSaveXmlVarNull33881AotTest extends TestCase
{
    public function testAotVariableNullMatchesLiteralNullDocumentDump(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_dom_savexml_var_null.php';
        $bin = sys_get_temp_dir().'/phpc_savexml_var_null_33881_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $got = implode("\n", $runOut)."\n";
            $this->assertStringContainsString("<?xml version=\"1.0\"?>\n<r><a/></r>", $got);
            $this->assertSame(2, substr_count($got, '<r><a/></r>'), $got);
        } finally {
            @unlink($bin);
        }
    }
}
