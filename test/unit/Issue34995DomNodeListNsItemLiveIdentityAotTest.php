<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getElementsByTagNameNS()->item() must return the live tree node (#34995).
 *
 * Leftover of #34983: the NS branch still always rematerialized a detached clone.
 *
 * @see php-src ext/dom/nodelist.c php_dom_nodelist_item
 * @see php-src ext/dom/node.c dom_node_remove_child
 *
 * @group llvm
 * @group aot
 */
final class Issue34995DomNodeListNsItemLiveIdentityAotTest extends TestCase
{
    private const EXPECTED_REMOVE = "3|r|same|2|2\n";

    private const EXPECTED_IDENTITY = "p|same|u|a\n";

    public function testAotNsItemRemoveChildMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_getelementsbytagnamens_item_removechild_aot_34995.php';
        $bin = sys_get_temp_dir().'/phpc_34995_ns_rm_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED_REMOVE, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testAotNsItemIdentityMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_getelementsbytagnamens_item_identity_aot_34995.php';
        $bin = sys_get_temp_dir().'/phpc_34995_ns_id_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED_IDENTITY, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testPreferLiveNsCommentPresent(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/dom/JitDomNodeListItemUserScript.php'
        );
        $this->assertStringContainsString('#34995', $src);
        $this->assertStringContainsString('itemAtNs', $src);
        $walk = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/dom/JitDomLiveElementsByTagWalk.php'
        );
        $this->assertStringContainsString('function itemAtNs', $walk);
        $this->assertStringContainsString('emitNodeMatchesNs', $walk);
    }
}
