<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: IntlChar::* ClassConstFetch seeds (#35412 peer #35408).
 *
 * php-src: ext/intl/uchar — REGISTER_INTLCHAR_CLASS_CONST_* (PROPERTY_*, FOLD_CASE_*).
 *
 * @group llvm
 */
final class IntlCharClassConstSeed35412AotTest extends TestCase
{
    public function testSeedHookExists(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Builtin/Type/Object_.php'
        );
        $this->assertStringContainsString('VmIntlChar::classConstants()', $src);
        $this->assertStringContainsString('#35412', $src);
    }

    public function testAotIntlCharConstsMatch(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_intlchar_const_seed.php';
        $this->assertFileExists($repro);
        $bin = sys_get_temp_dir().'/ichar_cc_35412_'.getmypid();
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
                "PROPERTY_ALPHABETIC=0\nPROPERTY_WHITE_SPACE=31\nFOLD_CASE_DEFAULT=0\n",
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
