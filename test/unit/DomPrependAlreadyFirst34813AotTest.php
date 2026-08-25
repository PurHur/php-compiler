<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: ParentNode::prepend(already-first-child) matches Zend (#34813).
 *
 * @group llvm
 * @group aot
 */
final class DomPrependAlreadyFirst34813AotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
prepend_same=<r><a/><b/></r>
prepend_ab=<r><a/><b/></r>
prepend_move=<r><b/><a/></r>
prepend_reorder=<r><c/><a/><b/></r>
insertBefore_self=Error
TXT;

    public function testVmPrependAlreadyFirstMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34813_dom_prepend_already_first_aot.php';
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

    public function testAotPrependAlreadyFirstMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34813_dom_prepend_already_first_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_prepend_34813_'.getmypid().'.bin';
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
