<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::DROP_NEW_LINE strips trailing newlines (#33390).
 *
 * @see php-src ext/spl/spl_directory.c spl_filesystem_file_read / DROP_NEW_LINE
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectDropNewLine33390AotTest extends TestCase
{
    public function testZendAndVmMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_drop_new_line_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertSame("line=[a]\ncur=[a]\nflags=1\n", $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");
    }

    public function testHelperAppliesDropNewLine(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_drop_new_line.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('emitApplyDropNewLine', $helper);
        $this->assertStringContainsString('FLAG_DROP_NEW_LINE', $helper);
        $this->assertStringContainsString('#33390', $helper);
        $this->assertStringContainsString('suffixIdentical', $helper);
    }
}
