<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::SKIP_EMPTY omits blank lines on foreach (#33396).
 *
 * @see php-src ext/spl/spl_directory.c spl_filesystem_file_read_line / SKIP_EMPTY
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectSkipEmpty33396AotTest extends TestCase
{
    public function testZendAndVmMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_skip_empty_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertSame("[a]|[b]|[]\n", $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");
    }

    public function testHelperAppliesSkipEmptyOnIteratorNotFgets(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_skip_empty.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('FLAG_SKIP_EMPTY', $helper);
        $this->assertStringContainsString('#33396', $helper);
        $this->assertStringContainsString('applySkipEmpty', $helper);
        // fgets must not skip empties (Zend zim_SplFileObject_fgets).
        $this->assertMatchesRegularExpression(
            '/compileFgets[\s\S]*?emitReadLineToValueBox\(\$context, \$receiver, 1, false\)/',
            $helper
        );
        // Foreach must use Iterator protocol, not construct-time HT snapshot.
        $outer = (string) file_get_contents($root.'/lib/VM/SplOuterIteratorHt.php');
        $this->assertStringNotContainsString("'splfileobject'", $outer);
        $this->assertStringContainsString('#33396', $outer);
    }
}
