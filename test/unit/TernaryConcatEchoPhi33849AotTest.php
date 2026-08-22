<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: CONCAT inside ternary true arm must store the echo-phi slot (#33849 / peer #32908).
 *
 * @group llvm
 * @group aot
 */
final class TernaryConcatEchoPhi33849AotTest extends TestCase
{
    public function testTernaryConcatEchoMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33849_ternary_concat_echo.php');
    }

    public function testStackPhiDetectsConcatIntoResultSlot(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#33849', $src);
        $this->assertStringContainsString('TYPE_CONCAT === $branchOp->type', $src);
        $this->assertStringContainsString('concatPhiOp', $src);
        $this->assertStringContainsString('coalesceMergeSlotOperands[$destSlot]', $src);
        $this->assertStringContainsString('detachObjectPropertyStringForConcat', $src);
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
        $bin = sys_get_temp_dir().'/ao_33849_'.getmypid().'_'.md5($src);
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
