<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: ternary true-arm array of property fetches must match Zend (#34944).
 *
 * mergeTernaryResultSlot must see FUNCCALL ARG_SEND of the ?: phi so stack-phi arms.
 *
 * @group llvm
 * @group aot
 */
final class Issue34944TernaryPropArrayAotTest extends TestCase
{
    public function testTernaryArrayOfPropertyFetchMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34944_ternary_prop_array.php');
    }

    public function testFalsyConditionYieldsNullArm(): void
    {
        $src = sys_get_temp_dir().'/issue_34944_falsy_'.getmypid().'.php';
        file_put_contents(
            $src,
            "<?php\nclass C { public \$x = 'hi'; }\n\$o = null;\nvar_export(\$o ? [\$o->x] : null);\necho \"\\n\";\n"
        );
        try {
            $this->assertAotMatchesZend($src);
        } finally {
            @unlink($src);
        }
    }

    public function testMergeTernaryResultSlotRecognizesArgSend(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#34944', $jit);
        $this->assertStringContainsString('TYPE_ARG_SEND === $mergeOp->type', $jit);
        $this->assertStringContainsString(
            'var_export($o ? [$o->x] : null)',
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
        $bin = sys_get_temp_dir().'/ao_34944_'.getmypid().'_'.md5($src);
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
