<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize(SplDoublyLinkedList/SplQueue/SplStack) non-empty must match Zend — no SIGABRT (#34592).
 *
 * @see php-src ext/spl/spl_dllist.c
 *
 * @group llvm
 * @group aot
 */
final class Issue34592DllistSerializeAotTest extends TestCase
{
    public function testAotSerializeDllistMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_spl_dllist_serialize_33966.php';
        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expected = implode("\n", $zendOut)."\n";

        $bin = sys_get_temp_dir().'/phpc_issue_34592_dll_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testCompileSerializeUsesHashtableAbiNotNestedJitWire(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/VM/SplDllistJitHelper.php');
        $this->assertStringContainsString('__compiler_serialize_hashtable', $src);
        $this->assertStringContainsString('#34592', $src);
        $this->assertStringNotContainsString(
            'SerializeSplDllistNestedJitHelper::encodeWire',
            $src
        );
    }
}
