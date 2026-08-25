<?php

declare(strict_types=1);

use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * AOT: ($g .= 'A') && ($g .= 'B') must yield g=AB (#34558).
 *
 * @group llvm
 * @group aot
 */
final class LogicalAndAssignConcat34558AotTest extends TestCase
{
    public function testAndOrAssignConcatMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_and_assign_concat.php');
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_or_assign_concat.php');
        $this->assertAotMatchesZend(__DIR__.'/../repro/maintainer_gap_and_assign_rhs.php');
    }

    public function testAssignOpFusesConcatWhenJumpIfReadsTemp(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            file_get_contents(__DIR__.'/../repro/aot_and_assign_concat.php'),
            'aot_and_assign_concat.php'
        );
        $this->assertNotNull($block);

        $inPlaceConcats = 0;
        $jumpIf = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONCAT === $op->type && null !== $op->arg1 && (int) $op->arg1 === (int) $op->arg2) {
                ++$inPlaceConcats;
            }
            if (OpCode::TYPE_JUMPIF === $op->type) {
                $jumpIf = $op;
            }
        }
        $this->assertGreaterThanOrEqual(1, $inPlaceConcats, 'expected in-place CONCAT($g,$g,…) after AssignOp fusion');
        $this->assertNotNull($jumpIf);
        $this->assertNotNull($jumpIf->arg1);

        // Long arm must also fuse (Cast_Bool reader) so AOT matches Zend (#34558).
        $longArm = $jumpIf->block1;
        $this->assertNotNull($longArm);
        $longInPlace = false;
        $castOnCv = false;
        foreach ($longArm->opCodes as $op) {
            if (OpCode::TYPE_CONCAT === $op->type && null !== $op->arg1 && (int) $op->arg1 === (int) $op->arg2) {
                $longInPlace = true;
            }
            if (OpCode::TYPE_CAST_BOOL === $op->type && null !== $op->arg2 && (int) $op->arg2 === (int) $jumpIf->arg1) {
                $castOnCv = true;
            }
        }
        $this->assertTrue($longInPlace, '&& long arm must in-place CONCAT after Cast_Bool retarget');
        $this->assertTrue($castOnCv, 'Cast_Bool must read the CV, not the dead concat temp');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot, basename($src));
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
        $bin = sys_get_temp_dir().'/ao_34558_'.getmypid().'_'.md5($src);
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
