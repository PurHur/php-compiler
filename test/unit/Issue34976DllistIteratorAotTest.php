<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplDoublyLinkedList / SplStack Iterator rewind/valid/current/next (#34976).
 *
 * Thin AOT registered push/count/top but not Iterator methods → silent null (#579).
 *
 * @see php-src ext/spl/spl_dllist.c zim_SplDoublyLinkedList_rewind / valid / current / next
 *
 * @group llvm
 * @group aot
 */
final class Issue34976DllistIteratorAotTest extends TestCase
{
    private const EXPECTED = "3|0|2|0,1,2,\nc,b,a,\n";

    public function testAotIteratorProtocolMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34976_dllist_iterator_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34976_dllist_'.getmypid().'.bin';
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

    public function testIteratorProxiesRegistered(): void
    {
        $ctx = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString("'rewind', 'valid', 'current', 'key', 'next'", $ctx);
        $this->assertStringContainsString('#34976', $ctx);
        $helper = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/SplDllistJitHelper.php'
        );
        $this->assertStringContainsString('PROP_ITER_POS', $helper);
        $this->assertStringContainsString('function compileRewind', $helper);
        $this->assertStringContainsString('function compileValid', $helper);
    }
}
