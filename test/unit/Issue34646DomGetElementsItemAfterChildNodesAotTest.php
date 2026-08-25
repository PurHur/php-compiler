<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: held getElementsByTagName NodeList::item() after childNodes fetch (#34646).
 *
 * @see php-src ext/dom/nodelist.c
 *
 * @group llvm
 * @group aot
 */
final class Issue34646DomGetElementsItemAfterChildNodesAotTest extends TestCase
{
    public function testLiveItemTagQuerySurvivesClearTagQueryState(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/dom/JitDomGetElementsByTagNameUserScript.php'
        );
        $this->assertStringContainsString('liveItemTagQuery', $src);
        $this->assertStringContainsString('#34646', $src);
        $item = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/dom/JitDomNodeListItemUserScript.php'
        );
        $this->assertStringContainsString('selectTagWalkUnlessChildNodesOwner', $item);
    }

    public function testAotItemAfterChildNodesFetch(): void
    {
        $this->assertAotMatchesZend(
            dirname(__DIR__, 2).'/test/repro/issue_34646_dom_getelements_item_after_childnodes_aot.php',
            "len=4\nr,a,b,c,\n"
        );
    }

    public function testAotItemAfterMiddleRemoveChild(): void
    {
        $this->assertAotMatchesZend(
            dirname(__DIR__, 2).'/test/repro/issue_34646_dom_getelements_item_after_remove_aot.php',
            "before=4\nafter=3\nr,a,c,\n"
        );
    }

    private function assertAotMatchesZend(string $src, string $expect): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_34646_'.getmypid().'_'.md5($src).'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
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
            $this->assertSame($expect, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
