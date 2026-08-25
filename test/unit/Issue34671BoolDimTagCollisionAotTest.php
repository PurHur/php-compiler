<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: script-global bool dims must coerce true→1 (#34671 / residual #34667).
 *
 * JIT TYPE_NATIVE_BOOL (2) collides with VM TYPE_FLOAT (2); long-arg / dim lowering
 * must match bool before treating tag 2 as float.
 *
 * @group aot
 */
final class Issue34671BoolDimTagCollisionAotTest extends TestCase
{
    public function testScriptGlobalBoolDimMatchesZendUnderAot(): void
    {
        $src = dirname(__DIR__).'/repro/aot_bool_array_dim.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $this->assertSame("7\nbool(true)\n", $zend, 'Zend reference');

        $bin = sys_get_temp_dir().'/issue_34671_'.getmypid().'.bin';
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
            'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR' => sys_get_temp_dir().'/phpc-34671-'.getmypid(),
        ]);
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        return ['rc' => $rc, 'out' => $out];
    }
}
