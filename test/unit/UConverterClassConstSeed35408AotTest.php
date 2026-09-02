<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: UConverter::* ClassConstFetch seeds (#35408 peer #35401).
 *
 * php-src: ext/intl/converter/converter.c — REGISTER_UCONVERTER_CLASS_CONST_LONG.
 *
 * @group llvm
 */
final class UConverterClassConstSeed35408AotTest extends TestCase
{
    public function testSeedHookExists(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../ext/intl/Module.php'
        );
        $this->assertStringContainsString('VmUConverter::classConstants()', $src);
        $this->assertStringContainsString('#35408', $src);
    }

    public function testAotUConverterConstsMatch(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_uconverter_const_seed.php';
        $this->assertFileExists($repro);
        $bin = sys_get_temp_dir().'/uc_cc_35408_'.getmypid();
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
            $this->assertSame(
                "REASON_ILLEGAL=1\nUTF8=4\nUS_ASCII=26\n",
                $aot['out']
            );
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
