<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: IntlBreakIterator::* ClassConstFetch seeds (#35401 peer #35397).
 *
 * php-src: ext/intl/breakiterator/breakiterator_class.c — REGISTER_INTLBREAKITERATOR_CLASS_CONST_LONG.
 *
 * @group llvm
 */
final class IntlBreakIteratorClassConstSeed35401AotTest extends TestCase
{
    public function testSeedHookExists(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../ext/intl/Module.php'
        );
        $this->assertStringContainsString('VmBreakIterator::classConstants()', $src);
        $this->assertStringContainsString('#35401', $src);
    }

    public function testAotIntlBreakIteratorConstsMatch(): void
    {
        $repro = dirname(__DIR__).'/repro/aot_intlbreakiterator_const_seed.php';
        $this->assertFileExists($repro);
        $bin = sys_get_temp_dir().'/ibi_cc_35401_'.getmypid();
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
                "DONE=-1\nWORD_NONE=0\nWORD_LETTER=200\n",
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
