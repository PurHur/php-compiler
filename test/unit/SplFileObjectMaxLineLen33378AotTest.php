<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject setMaxLineLen must truncate fgets (#33378).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_setMaxLineLen / spl_filesystem_file_read
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectMaxLineLen33378AotTest extends TestCase
{
    public function testZendAndVmMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_maxlen_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('max=4', $zend);
        $this->assertStringContainsString("line='abcd'", $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");
    }

    public function testAotMatchesZendAndUsesMaxLineBuffer(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_maxlen_aot.php';
        $bin = sys_get_temp_dir().'/phpc_sfo_maxlen_'.getmypid().'.bin';
        @unlink($bin);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '.escapeshellarg($repro).' 2>&1',
            $compileOut,
            $compileRc
        );
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
        @unlink($bin);
        $this->assertSame(0, $aotRc, implode("\n", $aotOut));
        $aot = implode("\n", $aotOut)."\n";
        $this->assertStringContainsString('max=4', $aot);
        $this->assertStringContainsString("line='abcd'", $aot);

        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('fgetsBufferLen', $helper);
        $this->assertStringContainsString('#33378', $helper);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_maxlen.c');
    }
}
