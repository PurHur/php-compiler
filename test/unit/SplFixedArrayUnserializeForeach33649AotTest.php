<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach after unserialize(SplFixedArray) must match Zend (#33649).
 *
 * @group llvm
 * @group aot
 */
final class SplFixedArrayUnserializeForeach33649AotTest extends TestCase
{
    public function testForeachMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/splfixedarray_unserialize_foreach_aot_33649.php');
    }

    public function testClassUserTypePropagateWired(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('propagateUnserializeSplFixedArrayResultType', $src);
        $this->assertStringContainsString('#33649', $src);
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
        $bin = sys_get_temp_dir().'/sfa_fe_33649_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
