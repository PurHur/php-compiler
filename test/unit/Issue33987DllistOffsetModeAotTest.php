<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplDoublyLinkedList offsetGet/offsetExists + setIteratorMode/getIteratorMode (#33987).
 *
 * Thin AOT registered push/count/isEmpty but camelCase offsetGet/setIteratorMode proxy keys
 * were mixed-case and missed the lowercase lookup table → silent null (#579).
 *
 * @see php-src ext/spl/spl_dllist.c zim_SplDoublyLinkedList_offsetGet / setIteratorMode
 *
 * @group llvm
 * @group aot
 */
final class Issue33987DllistOffsetModeAotTest extends TestCase
{
    private const EXPECTED = "b|y|3\nb|y\nmode=2\nc,b,a,\nstack_mode=6\nqueue_mode=4\n";

    public function testAotOffsetAndIteratorModeMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33987_dllist_offset_mode_aot.php';
        $bin = sys_get_temp_dir().'/phpc_33987_dllist_'.getmypid().'.bin';
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

    public function testProxyKeysAreLowercase(): void
    {
        $ctx = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies[\$dllLc.'::'.strtolower(\$dllMethod)]",
            $ctx
        );
        $this->assertStringContainsString('setIteratorMode', $ctx);
        $this->assertStringContainsString('offsetGet', $ctx);
        $this->assertStringContainsString('#33987', $ctx);
    }
}
