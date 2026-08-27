<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: stream_filter_append string.toupper / rot13 (#35426).
 *
 * Pure-LLVM filter registry + apply (NestedJIT apply SEGVs under thin AOT).
 *
 * php-src: ext/standard/streamsfuncs.c
 *
 * @group llvm
 */
final class StreamFilterApply35426AotTest extends TestCase
{
    public function testReproExists(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_stream_filter_toupper_35426.php';
        $this->assertFileExists($repro);
        $zend = $this->runCmd([PHP_BINARY, $repro]);
        $this->assertSame(0, $zend['code'], $zend['out']);
        $this->assertSame("HELLO\nnop\n", $zend['out']);
    }

    /**
     * @group llvm
     */
    public function testAotToupperAndRot13MatchZend(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_stream_filter_toupper_35426.php';
        $bin = sys_get_temp_dir().'/sf_35426_'.getmypid();
        $compile = $this->runCmd([
            PHP_BINARY,
            dirname(__DIR__, 2).'/bin/compile.php',
            '-o',
            $bin,
            $repro,
        ]);
        $this->assertSame(0, $compile['code'], $compile['out']);
        $this->assertFileExists($bin);
        try {
            $aot = $this->runCmd([$bin]);
            $this->assertSame(0, $aot['code'], $aot['out']);
            $this->assertSame("HELLO\nnop\n", $aot['out']);
        } finally {
            @unlink($bin);
        }
    }

    /** @return array{code:int,out:string} */
    private function runCmd(array $cmd): array
    {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $code, 'out' => $out];
    }
}
