<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: importNode(createComment/CDATA/PI) leftover of #35098 (#35871).
 *
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, importNode)
 *
 * @group llvm
 * @group aot
 */
final class Issue35871ImportNodeCreateLeavesAotTest extends TestCase
{
    public function testAotCreateLeavesImportMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_dom_importnode_create_comment.php';
        $this->assertFileExists($src);

        $vm = [];
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1',
            $vm,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vm));
        $vmOut = implode("\n", $vm)."\n";
        $this->assertStringContainsString('comment name=#comment type=8 val=hi', $vmOut);
        $this->assertStringContainsString('<r><!--hi--></r>', $vmOut);
        $this->assertStringContainsString('cdata name=#cdata-section type=4 val=x', $vmOut);
        $this->assertStringContainsString('<![CDATA[x]]>', $vmOut);
        $this->assertStringContainsString('pi name=pi type=7 val=data', $vmOut);
        $this->assertStringContainsString('<?pi data?>', $vmOut);

        $bin = sys_get_temp_dir().'/phpc_import_create_35871_'.getmypid().'.bin';
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
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
