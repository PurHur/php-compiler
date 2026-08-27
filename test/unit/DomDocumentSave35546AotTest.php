<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMDocument::save must not ExternalMethod-null (#35546).
 *
 * @see php-src ext/dom/php_dom.c zim_DOMDocument_save
 *
 * @group llvm
 * @group aot
 */
final class DomDocumentSave35546AotTest extends TestCase
{
    public function testSaveAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $src = __DIR__.'/../repro/aot_dom_document_save_null.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testProxyWiredInDomInstanceMethodJit(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString("'domdocument::save' => true", $jit);
        $this->assertStringContainsString('new Call\\DomDocumentSave()', $jit);
        $helper = (string) file_get_contents($root.'/ext/dom/JitDomSave.php');
        $this->assertStringContainsString('JitFilePutContents::invoke', $helper);
        $this->assertStringContainsString('JitDomSaveXML::invoke', $helper);
    }

    private function runVm(string $src): string
    {
        return $this->runPhp('bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_save_35546_'.getmypid().'_'.md5($src);
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
