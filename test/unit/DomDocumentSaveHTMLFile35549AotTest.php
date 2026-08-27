<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMDocument::saveHTMLFile must write bytes (#35549).
 *
 * @see php-src ext/dom/php_dom.c zim_DOMDocument_saveHTMLFile
 *
 * @group llvm
 * @group aot
 */
final class DomDocumentSaveHTMLFile35549AotTest extends TestCase
{
    public function testSaveHTMLFileAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $src = __DIR__.'/../repro/aot_dom_document_savehtmlfile_null.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testProxyWiredInDomInstanceMethodJit(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString("'domdocument::savehtmlfile' => true", $jit);
        $invoke = (string) file_get_contents($root.'/ext/dom/VmDomInstanceInvoke.php');
        $this->assertStringContainsString("'savehtmlfile'", $invoke);
        $lowering = (string) file_get_contents($root.'/ext/dom/JitDomSaveHTMLFile.php');
        $this->assertStringContainsString('JitDomSaveHTML::invoke', $lowering);
        $this->assertStringContainsString('JitFilePutContents::invoke', $lowering);
    }

    private function runVm(string $src): string
    {
        return $this->runPhp('bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_shf_35549_'.getmypid().'_'.md5($src);
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile.' 2>&1', $cout, $crc);
            $this->assertSame(0, $crc, implode("\n", $cout));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>/dev/null', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            @unlink($bin);
            chdir($cwd);
        }
    }

    private function runPhp(string $relBin, string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/'.$relBin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>/dev/null', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }
}
