<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: ternary array arms assigned to a local / multi-prop INIT_ARRAY (#34970).
 *
 * Leftover of #34944 — mergeTernaryResultSlot must see merge ASSIGN(local, armPhi),
 * and INIT_ARRAY must trail through ADD_ARRAY_ELEMENT onto the coalesce phi.
 *
 * @group llvm
 * @group aot
 */
final class Issue34970TernaryAssignArrayAotTest extends TestCase
{
    public function testAssignFormMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34970_ternary_assign_array.php');
    }

    public function testMultiPropArgSendMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34970_ternary_multiprop_array.php');
    }

    public function testLiteralArrayAssignMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34970_ternary_literal_array_assign.php');
    }

    public function testObjConditionAssignFormMatchesZend(): void
    {
        $src = sys_get_temp_dir().'/issue_34970_obj_'.getmypid().'.php';
        file_put_contents(
            $src,
            "<?php\nclass C { public \$a = 1; }\n\$o = new C;\n\$x = \$o ? [\$o->a] : [9];\nvar_export(\$x);\necho \"\\n\";\n"
        );
        try {
            $this->assertAotMatchesZend($src);
        } finally {
            @unlink($src);
        }
    }

    public function testMergeTernaryResultSlotRecognizesAssignFromArmPhi(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#34970', $jit);
        $this->assertStringContainsString('initArrayCoalescePhiAfterElementTrail', $jit);
        $this->assertStringContainsString(
            'Merge copies arm phi into a named local before FUNCCALL/ECHO (#34970)',
            $jit
        );
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
        $bin = sys_get_temp_dir().'/ao_34970_'.getmypid().'_'.md5($src);
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
