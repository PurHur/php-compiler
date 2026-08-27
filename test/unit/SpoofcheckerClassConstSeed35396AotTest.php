<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: Spoofchecker::* ClassConstFetch seeds (#35396 peer #35389).
 *
 * php-src: ext/intl/spoofchecker/spoofchecker_class.c — REGISTER_SPOOFCHECKER_CLASS_CONST_LONG.
 *
 * @group llvm
 */
final class SpoofcheckerClassConstSeed35396AotTest extends TestCase
{
    public function testSeedHookExists(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Builtin/Type/Object_.php'
        );
        $this->assertStringContainsString('VmSpoofchecker::classConstants()', $src);
        $this->assertStringContainsString('#35396', $src);
    }

    public function testAotSpoofcheckerConstsMatch(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_spoofchecker_const_seed.php';
        $this->assertFileExists($repro);
        $bin = sys_get_temp_dir().'/spoofchecker_cc_35396_'.getmypid();
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
            $this->assertSame("SINGLE_SCRIPT=16\nINVISIBLE=32\nALL_CHECKS=65535\n", $aot['out']);
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
