<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: Transliterator::* ClassConstFetch seeds (#35384 peer #35379).
 *
 * php-src: ext/intl/transliterator/transliterator_class.c — REGISTER_TRANSLITERATOR_CLASS_CONST_LONG.
 *
 * @group llvm
 */
final class TransliteratorClassConstSeed35384AotTest extends TestCase
{
    public function testSeedHookExists(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Builtin/Type/Object_.php'
        );
        $this->assertStringContainsString('VmTransliterator::classConstants()', $src);
        $this->assertStringContainsString('#35384', $src);
    }

    public function testAotTransliteratorConstsMatch(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_transliterator_const_seed.php';
        $this->assertFileExists($repro);
        $bin = sys_get_temp_dir().'/transliterator_cc_35384_'.getmypid();
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
            $this->assertSame("FORWARD=0\nREVERSE=1\n", $aot['out']);
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
