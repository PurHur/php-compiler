<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_reduce string user-fn / builtin must compile (#33721).
 *
 * @group llvm
 * @group aot
 */
final class Issue33721ArrayReduceStringCallbackAotTest extends TestCase
{
    public function testStringCallbacksMatchZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33721_array_reduce_string_callback.php');
    }

    public function testUserFunctionLlvmGuardPresent(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/ArrayReduceLlvm.php');
        $this->assertStringContainsString('reduceWithUserFunction', $src);
        $this->assertStringContainsString('#33721', $src);
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayReduceRuntime.php');
        $this->assertStringContainsString('reduceWithUserFunction', $runtime);
        $helper = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/ArrayReduceJitHelper.php');
        $this->assertStringNotContainsString('VmClosureInvoke', $helper);
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
        $bin = sys_get_temp_dir().'/ao_33721_'.getmypid().'_'.md5($src);
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
