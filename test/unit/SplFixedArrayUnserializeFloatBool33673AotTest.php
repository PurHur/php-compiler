<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFixedArray unserialize float/bool (#33673).
 *
 * @group llvm
 * @group aot
 */
final class SplFixedArrayUnserializeFloatBool33673AotTest extends TestCase
{
    public function testUnserializeFloatBoolMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/splfixedarray_unserialize_float_bool_33673.php');
    }

    public function testHelpersWired(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/ext/standard/UnserializeSplFixedArrayDoubleNestedJitHelper.php');
        $this->assertFileExists($root.'/ext/standard/UnserializeSplFixedArrayBoolNestedJitHelper.php');
        $this->assertFileExists($root.'/ext/standard/phpc_native_ht_set_double_at.php');
        $this->assertFileExists($root.'/ext/standard/phpc_native_ht_set_bool_at.php');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFixedArrayJitHelper.php');
        $this->assertStringContainsString('UnserializeSplFixedArrayDoubleNestedJitHelper', $helper);
        $this->assertStringContainsString('#33673', $helper);
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
        $bin = sys_get_temp_dir().'/sf_unser_33673_'.getmypid().'_'.md5($src);
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
