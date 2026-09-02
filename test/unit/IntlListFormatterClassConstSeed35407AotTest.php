<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: IntlListFormatter::* ClassConstFetch seeds (#35407 peer #35401).
 *
 * php-src: ext/intl/listformatter/listformatter_class.c — REGISTER_INTLLISTFORMATTER_CLASS_CONST_LONG.
 *
 * @group llvm
 */
final class IntlListFormatterClassConstSeed35407AotTest extends TestCase
{
    public function testSeedHookExists(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../ext/intl/Module.php'
        );
        $this->assertStringContainsString('VmIntlListFormatter::classConstants()', $src);
        $this->assertStringContainsString('#35407', $src);
    }

    public function testAotIntlListFormatterConstsMatch(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_intllistformatter_const_seed.php';
        $this->assertFileExists($repro);
        $bin = sys_get_temp_dir().'/ilf_cc_35407_'.getmypid();
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
                "TYPE_AND=0\nWIDTH_WIDE=0\n",
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
