<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: boxed bool ⊕ int must coerce true→1 (#34678).
 *
 * emitBoxedNumericResult long arm must use JitLongArg::lower (bool before readLong).
 *
 * @group aot
 */
final class Issue34678BoxedBoolIntArithAotTest extends TestCase
{
    public function testBoxedBoolIntArithMatchesZendUnderAot(): void
    {
        $src = dirname(__DIR__).'/repro/aot_boxed_bool_int_arith.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $this->assertSame(
            "int(3)\nint(2)\nint(3)\nint(0)\n",
            $zend,
            'Zend reference'
        );

        $bin = sys_get_temp_dir().'/issue_34678_'.getmypid().'.bin';
        $compile = $this->runCmd([
            PHP_BINARY,
            dirname(__DIR__, 2).'/bin/compile.php',
            '-o',
            $bin,
            $src,
        ]);
        $this->assertSame(0, $compile['rc'], "AOT compile failed:\n".$compile['out']);
        $this->assertFileExists($bin);

        $aot = $this->runCmd([$bin]);
        @unlink($bin);
        $this->assertSame(0, $aot['rc'], "AOT run failed:\n".$aot['out']);
        $this->assertSame($zend, $aot['out']);
    }

    private function runPhp(string $file): string
    {
        $r = $this->runCmd([PHP_BINARY, $file]);
        $this->assertSame(0, $r['rc'], $r['out']);

        return $r['out'];
    }

    /**
     * @param list<string> $cmd
     * @return array{rc:int,out:string}
     */
    private function runCmd(array $cmd): array
    {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, dirname(__DIR__, 2), [
            'PHP_COMPILER_HELPER_RUNTIME_O' => '0',
            'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR' => sys_get_temp_dir().'/phpc-34678-'.getmypid(),
        ]);
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        return ['rc' => $rc, 'out' => $out];
    }
}
