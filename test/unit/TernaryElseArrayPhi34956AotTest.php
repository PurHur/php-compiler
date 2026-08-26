<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: ternary else-arm array literal must not collapse to NULL (#34956 / leftover #34944).
 *
 * @group llvm
 * @group aot
 */
final class TernaryElseArrayPhi34956AotTest extends TestCase
{
    public function testTernaryElseArrayMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34956_ternary_else_array_aot.php');
    }

    public function testInitArrayAndAssignRedirectToCoalescePhi(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#34956', $src);
        $this->assertStringContainsString('mergeIsArgSendPhi', $src);
        $this->assertStringContainsString('coalesceMergeSlotOperands[(int) $op->arg1]', $src);
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
