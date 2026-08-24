<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Attr textContent write must refresh saveXML open-tag (#34305 / re-#33864).
 *
 * php-src: ext/dom/node.c — dom_node_textContent_write / Attr value.
 *
 * @group llvm
 * @group aot
 */
final class DomAttrTextContentWriteSaveXml34305AotTest extends TestCase
{
    public function testAotAttrTextContentWriteUpdatesSaveXml(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/dom_attr_textcontent_write_savexml_aot.php';
        $out = $this->runAot($src);
        $this->assertSame($this->runVm($src), $out);
        $this->assertStringContainsString('<r a="w"/>', $out);
        $this->assertStringNotContainsString('<r a="v"/>', $out);
    }

    public function testEmitAttrValueSlotSyncUsesFetchedKey(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/dom/JitDomElementTextContent.php');
        $this->assertStringContainsString('lastFetchedKey()', $src);
        $this->assertStringContainsString('syncOwnerElementSaveXmlAfterAttrValueWrite', $src);
        $this->assertStringContainsString('refreshCompileTimeXmlRootAttributeSet', $src);
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.3 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_attr_tc_savexml_34305_'.getmypid();
        $cmd = 'env PHP_COMPILER_PROFILE=8.3 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            @unlink($bin);
            chdir($cwd);
        }
    }
}
