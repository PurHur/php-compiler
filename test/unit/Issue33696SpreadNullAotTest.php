<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: array spread / ArrayObject|ArrayIterator construct keep TYPE_NULL (#33696).
 *
 * @group llvm
 * @group aot
 */
final class Issue33696SpreadNullAotTest extends TestCase
{
    public function testSpreadCountMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33696_spread_null.php');
    }

    public function testSpreadPackedIntoSkipsUndefinedNotNull(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/JIT/HashTableWriteLlvm.php');
        $start = strpos($src, 'private static function spreadPackedInto');
        $this->assertNotFalse($start);
        $end = strpos($src, 'private static function spreadStringKeysInto', $start);
        $this->assertNotFalse($end);
        $fn = substr($src, $start, $end - $start);
        $this->assertStringContainsString('#33696', $fn);
        $this->assertStringContainsString('TYPE_UNDEFINED', $fn);
        $this->assertStringNotContainsString('__hashtable__offsetIsSet', $fn);
    }

    public function testUnsetHoleStillOmittedFromSpread(): void
    {
        $src = sys_get_temp_dir().'/ao_spread_unset_33696_'.getmypid().'.php';
        file_put_contents(
            $src,
            "<?php \$a=[1,2,3]; unset(\$a[1]); echo count([...\$a]), PHP_EOL;\n"
        );
        try {
            $this->assertAotMatchesZend($src);
        } finally {
            @unlink($src);
        }
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
        $bin = sys_get_temp_dir().'/ao_spread_33696_'.getmypid().'_'.md5($src);
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
