<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fstat via live stream handle (#33359).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fstat
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFstat33359AotTest extends TestCase
{
    public function testZendAndVmMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fstat_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('size=2', $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");
    }

    public function testProxiesAndNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fstat.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFstat', $helper);
        $this->assertStringContainsString('#33359', $helper);
        $this->assertStringContainsString('StatPathRuntime', $helper);
        $this->assertStringContainsString('__hashtable__setStringKeyLong', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fstat'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'ftell', 'fstat', 'flock'", $ctx);
    }
}
