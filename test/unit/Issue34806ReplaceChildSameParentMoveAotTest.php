<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: same-parent replaceChild / replaceWith must unlink (not hang) (#34806).
 *
 * @see php-src ext/dom/node.c dom_node_replace_child
 * @see php-src ext/dom/parentnode.c dom_parent_node_replace_with
 *
 * @group llvm
 * @group aot
 */
final class Issue34806ReplaceChildSameParentMoveAotTest extends TestCase
{
    public function testVmReplaceChildSameParentMove(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34806_replacechild_same_parent_move_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34806_replacechild_same_parent_move_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("replaceChild=<r><c/></r>\nreplaceWith=<r><c/></r>\n", $out);
    }

    public function testAotReplaceChildSameParentMoveMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34806_replacechild_same_parent_move_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34806_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));

        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(implode("\n", $zendOut), implode("\n", $runOut));
            }
        } finally {
            @unlink($bin);
        }
    }
}
