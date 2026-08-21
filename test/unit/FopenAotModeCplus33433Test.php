<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT fopen PHP modes c+/x+ via open(2)+fdopen (#33433).
 *
 * @see php-src main/streams/plain_wrapper.c php_stream_parse_fopen_modes
 */
final class FopenAotModeCplus33433Test extends TestCase
{
    public function testAotFopenCplusAndXplusMatchZend(): void
    {
        $root = \dirname(__DIR__, 2);
        $repro = $root.'/test/repro/fopen_aot_mode_cplus.php';
        $this->assertFileExists($repro);

        $zend = $this->runCmd(['php', $repro]);
        $this->assertSame(0, $zend['code'], $zend['out'].$zend['err']);

        $bin = \sys_get_temp_dir().'/phpc_fopen_33433_'.\getmypid().'.bin';
        @\unlink($bin);
        $compile = $this->runCmd([
            'php',
            $root.'/bin/compile.php',
            '-o',
            $bin,
            $repro,
        ]);
        $this->assertSame(0, $compile['code'], $compile['out'].$compile['err']);
        $this->assertFileExists($bin);

        $aot = $this->runCmd([$bin]);
        @\unlink($bin);
        $this->assertSame(0, $aot['code'], $aot['out'].$aot['err']);
        $this->assertSame($zend['out'], $aot['out']);
    }

    public function testKernelEmitsPhpFopenPlainForCxModes(): void
    {
        $kernel = (string) \file_get_contents(
            \dirname(__DIR__, 2).'/ext/standard/JitStreamIoKernel.php'
        );
        $this->assertStringContainsString('__phpc_php_fopen_plain', $kernel);
        $this->assertStringContainsString('emitPhpFopenPlain', $kernel);
        $this->assertStringContainsString('#33433', $kernel);
    }

    /** @return array{code: int, out: string, err: string} */
    private function runCmd(array $cmd): array
    {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $desc, $pipes, \dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        \fclose($pipes[0]);
        $out = \stream_get_contents($pipes[1]);
        $err = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [
            'code' => \proc_close($proc),
            'out' => $out !== false ? $out : '',
            'err' => $err !== false ? $err : '',
        ];
    }
}
