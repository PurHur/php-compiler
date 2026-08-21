<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @group aot
 * @group llvm
 */
final class DomNormalizeAdjacentText33438AotTest extends TestCase
{
    public function testNormalizeMergesAdjacentCreateTextNodeStandins(): void
    {
        $repro = dirname(__DIR__).'/repro/dom_normalize_adjacent_text_aot.php';
        $this->assertFileExists($repro);

        $zend = $this->runPhp($repro);
        $bin = sys_get_temp_dir().'/dom_normalize_33438_'.getmypid();
        $compile = $this->runCmd([
            PHP_BINARY,
            dirname(__DIR__, 2).'/bin/compile.php',
            '-o',
            $bin,
            $repro,
        ]);
        $this->assertSame(0, $compile['code'], $compile['out']);
        $this->assertFileExists($bin);

        $aot = $this->runCmd([$bin]);
        @unlink($bin);
        $this->assertSame(0, $aot['code'], $aot['out']);
        $this->assertSame($zend['out'], $aot['out']);
        $this->assertStringContainsString('post_len=1', $aot['out']);
        $this->assertStringContainsString('text=ab', $aot['out']);
    }

    /** @return array{code:int,out:string} */
    private function runPhp(string $file): array
    {
        return $this->runCmd([PHP_BINARY, $file]);
    }

    /**
     * @param list<string> $cmd
     * @return array{code:int,out:string}
     */
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
