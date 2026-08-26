<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getElementsByTagName()->item() must return the live tree node (#34983).
 *
 * #34936 preferred compile-time rematerialize whenever markup had the Nth tag,
 * yielding a detached clone (null parentNode) so removeChild raised Not Found.
 *
 * @see php-src ext/dom/nodelist.c php_dom_nodelist_item
 * @see php-src ext/dom/node.c dom_node_remove_child
 *
 * @group llvm
 * @group aot
 */
final class Issue34983DomNodeListItemLiveIdentityAotTest extends TestCase
{
    private const EXPECTED = "3|r|same|2|2\n";

    public function testAotItemRemoveChildMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_getelementsbytagname_item_removechild_aot_34983.php';
        $bin = sys_get_temp_dir().'/phpc_34983_nl_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testPreferLiveCommentPresent(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/dom/JitDomNodeListItemUserScript.php'
        );
        $this->assertStringContainsString('#34983', $src);
        $this->assertStringContainsString('preferRemat', $src);
        $this->assertStringNotContainsString('markupHasNth', $src);
    }
}
