<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #35178 — AOT Generator::send + echo of received value (resume builder/intrinsic skew).
 *
 * @group llvm
 * @group aot
 */
final class GeneratorSendEchoReceived35178AotTest extends TestCase
{
    public function testSendEchoReceivedMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_generator_send_echo_received.php';
        $this->assertFileExists($src);

        $zend = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zrc);
        $this->assertSame(0, $zrc, "Zend failed:\n".implode("\n", $zend));
        $zendOut = implode("\n", $zend);
        $this->assertStringContainsString('got=hi', $zendOut);

        $vm = [];
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1',
            $vm,
            $vrc
        );
        $this->assertSame(0, $vrc, "VM failed:\n".implode("\n", $vm));
        $this->assertSame($zendOut, implode("\n", $vm), 'VM must match Zend');

        $bin = sys_get_temp_dir().'/phpc_gen_send_echo_35178_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));
        $this->assertFileExists($bin);

        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame($zendOut, implode("\n", $aot), 'AOT must match Zend send+echo');
        } finally {
            @unlink($bin);
        }
    }
}
