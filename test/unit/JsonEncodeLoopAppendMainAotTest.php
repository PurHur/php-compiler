<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode after loop `$rows[]=` / `$rows[$k]=` must not fold INIT `[]` (#36385 / peer #33709).
 *
 * @group llvm
 * @group aot
 */
final class JsonEncodeLoopAppendMainAotTest extends TestCase
{
    public function testLoopAppendMainMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/json_encode_loop_append_main.php');
    }

    public function testV2JsonRoundtripMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertAotMatchesZend($root.'/benchmarks/v2/json-roundtrip.php');
    }

    public function testCfgWideDimMutationGuardPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/JIT/CallUnpackCompileTime.php');
        $this->assertStringContainsString('cfgRoot', $src);
        $this->assertStringContainsString('#36385', $src);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/je_loop_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
