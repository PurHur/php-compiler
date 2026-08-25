<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: same-parent insertBefore / ChildNode::before must match Zend (#34803).
 *
 * @group llvm
 * @group aot
 */
final class InsertBeforeSameParentMove34803AotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
insertBefore_move=<r><c/><a/></r>
before_next=<r><c/><a/></r>
before_last=<r><c/><a/><b/></r>
before_already=<r><c/><a/></r>
before_str=<r>x<a/><c/></r>
insertBefore_self=Error
TXT;

    public function testVmInsertBeforeSameParentMoveMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34803_insertbefore_same_parent_move_aot.php';
        $zend = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zend));
        $this->assertSame(self::EXPECTED."\n", implode("\n", $zend)."\n");

        $vm = [];
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1',
            $vm,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vm));
        $this->assertSame(self::EXPECTED."\n", implode("\n", $vm)."\n");
    }

    public function testAotInsertBeforeSameParentMoveMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34803_insertbefore_same_parent_move_aot.php';
        $bin = sys_get_temp_dir().'/phpc_ib_move_34803_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED."\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
