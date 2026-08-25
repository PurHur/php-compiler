<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: boxed bool ⊕ float must coerce true→1.0 (#34674).
 *
 * JIT TYPE_NATIVE_BOOL (2) collides with VM TYPE_FLOAT (2) in
 * JitValueNumeric::valueBoxToDouble — bool must be matched before float.
 *
 * @group aot
 */
final class Issue34674BoxedBoolFloatArithAotTest extends TestCase
{
    public function testBoxedBoolFloatArithMatchesZendUnderAot(): void
    {
        $src = dirname(__DIR__).'/repro/aot_boxed_bool_float_arith.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $this->assertSame(
            "float(2.5)\nfloat(2.5)\nfloat(3)\nfloat(0.5)\n",
            $zend,
            'Zend reference'
        );

        $bin = sys_get_temp_dir().'/issue_34674_'.getmypid().'.bin';
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
            'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR' => sys_get_temp_dir().'/phpc-34674-'.getmypid(),
        ]);
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        return ['rc' => $rc, 'out' => $out];
    }
}
