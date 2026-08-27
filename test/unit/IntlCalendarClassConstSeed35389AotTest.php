<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: IntlCalendar::* ClassConstFetch seeds (#35389 peer #35384).
 *
 * php-src: ext/intl/calendar/calendar_class.c — REGISTER_INTLCALENDAR_CLASS_CONST_LONG.
 *
 * @group llvm
 */
final class IntlCalendarClassConstSeed35389AotTest extends TestCase
{
    public function testSeedHookExists(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Builtin/Type/Object_.php'
        );
        $this->assertStringContainsString('VmIntlCalendar::classConstants()', $src);
        $this->assertStringContainsString('#35389', $src);
    }

    public function testAotIntlCalendarConstsMatch(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_intlcalendar_const_seed.php';
        $this->assertFileExists($repro);
        $bin = sys_get_temp_dir().'/intlcalendar_cc_35389_'.getmypid();
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
            $this->assertSame("FIELD_YEAR=1\nFIELD_MONTH=2\nDOW_SUNDAY=1\n", $aot['out']);
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
