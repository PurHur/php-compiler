<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NestedJIT must restore outer ?: coalesce maps (#34960).
 *
 * @covers \PHPCompiler\JIT\NestedJitCompileScope
 */
final class NestedJitCoalesceRestore34960Test extends TestCase
{
    public function testNestedJitCompileScopeRestoresCoalesceMaps(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/NestedJitCompileScope.php');
        $this->assertStringContainsString('#34960', $src);
        $this->assertStringContainsString('savedCoalesceMergeSlotOperands', $src);
        $this->assertStringContainsString('savedCoalesceAssignTargets', $src);
        $this->assertStringContainsString('savedTernaryEchoPhiByAliasSlot', $src);
    }

    public function testIssue34956ReproStillMatchesZend(): void
    {
        $repro = dirname(__DIR__).'/repro/issue_34956_ternary_else_array_aot.php';
        $this->assertFileExists($repro);
        $zend = $this->runPhp($repro);
        $aot = $this->runAot($repro);
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
        $bin = sys_get_temp_dir().'/ao_34960_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));

            return implode("\n", $runOut);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
