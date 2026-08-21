<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::setMaxLineLen/getMaxLineLen + fgets truncation (#33378).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_setMaxLineLen / getMaxLineLen
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
        $this->assertStringContainsString('max=0', $zend);
        $this->assertStringContainsString('max2=4', $zend);
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

    public function testProxiesAndNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_maxlen.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileSetMaxLineLen', $helper);
        $this->assertStringContainsString('compileGetMaxLineLen', $helper);
        $this->assertStringContainsString('PROP_MAX_LINE', $helper);
        $this->assertStringContainsString('#33378', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'setmaxlinelen'", $call);
        $this->assertStringContainsString("'getmaxlinelen'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'setMaxLineLen', 'getMaxLineLen'", $ctx);
        $obj = (string) file_get_contents($root.'/lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('PROP_MAX_LINE', $obj);
    }
}
