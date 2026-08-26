<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: ternary else-arm array literal must match Zend (#34956).
 *
 * Leftover of #34944/#34948 — else Temporary for the phi slot must share the
 * coalesce stack-phi Variable ARG_SEND reads.
 *
 * @group llvm
 * @group aot
 */
final class Issue34956TernaryElseArrayAotTest extends TestCase
{
    public function testElseArmArrayLiteralMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34956_ternary_else_array_aot.php');
    }

    public function testTruthyArmStillMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34944_ternary_prop_array.php');
    }

    public function testRemapHelperPresent(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#34956', $jit);
        $this->assertStringContainsString('remapArmTemporaryOntoCoalesceMergeSlot', $jit);
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
        $bin = sys_get_temp_dir().'/ao_34956_'.getmypid().'_'.md5($src);
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
