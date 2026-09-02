<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: IntlTimeZone::* ClassConstFetch seeds (#35397 peer #35389).
 *
 * php-src: ext/intl/timezone/timezone_class.c — REGISTER_INTLTIMEZONE_CLASS_CONST_LONG.
 *
 * @group llvm
 */
final class IntlTimeZoneClassConstSeed35397AotTest extends TestCase
{
    public function testSeedHookExists(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../ext/intl/Module.php'
        );
        $this->assertStringContainsString('VmIntlTimeZone::classConstants()', $src);
        $this->assertStringContainsString('#35397', $src);
    }

    public function testAotIntlTimeZoneConstsMatch(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_intltimezone_const_seed.php';
        $this->assertFileExists($repro);
        $bin = sys_get_temp_dir().'/itz_cc_35397_'.getmypid();
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
                "DISPLAY_SHORT=1\nDISPLAY_LONG=2\nTYPE_CANONICAL=1\n",
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
