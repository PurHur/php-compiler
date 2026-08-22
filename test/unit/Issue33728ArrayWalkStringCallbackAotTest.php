<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_walk string user-fn / builtin must compile (#33728).
 *
 * @group llvm
 * @group aot
 */
final class Issue33728ArrayWalkStringCallbackAotTest extends TestCase
{
    public function testStringCallbacksMatchZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33728_array_walk_string_callback.php');
    }

    public function testNamedLlvmGuardPresent(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/ArrayWalkLlvm.php');
        $this->assertStringContainsString('walkWithUserFunction', $src);
        $this->assertStringContainsString('#33728', $src);
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayWalkRuntime.php');
        $this->assertStringContainsString('walkWithUserFunction', $runtime);
        $this->assertStringContainsString('dispatchStringCallback', $runtime);
        $this->assertStringNotContainsString('constantFromString($name)', $runtime);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertSame(
            "user:[11,12]\nintval:[1,2]\nassoc:{\"x\":15,\"y\":17}\nrec:[11,[12,13],14]",
            $zend
        );
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
        $bin = sys_get_temp_dir().'/ao_33728_'.getmypid().'_'.md5($src);
        $cmd = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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
