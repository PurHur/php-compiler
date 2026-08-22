<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_* packed walks keep TYPE_NULL (#33699).
 *
 * @group llvm
 * @group aot
 */
final class Issue33699ArrayNullPackedAotTest extends TestCase
{
    public function testArrayNullPackedMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33699_array_null_packed.php');
    }

    public function testUnsetHoleStillOmittedFromMerge(): void
    {
        $src = sys_get_temp_dir().'/ao_merge_unset_33699_'.getmypid().'.php';
        file_put_contents(
            $src,
            "<?php \$a=[1,2,3]; unset(\$a[1]); echo count(array_merge(\$a,[])), PHP_EOL;\n"
        );
        try {
            $this->assertAotMatchesZend($src);
        } finally {
            @unlink($src);
        }
    }

    public function testPackedIndexIsUndefinedHelperPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/JIT/HashTableReadLlvm.php');
        $this->assertStringContainsString('function packedIndexIsUndefined', $src);
        $this->assertStringContainsString('#33699', $src);
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
        $bin = sys_get_temp_dir().'/ao_arrnull_33699_'.getmypid().'_'.md5($src);
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
