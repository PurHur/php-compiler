<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: unserialize(SplFixedArray) restores __spl_ht (#33640).
 *
 * @group llvm
 * @group aot
 */
final class SplFixedArrayUnserialize33640AotTest extends TestCase
{
    public function testUnserializeMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/splfixedarray_unserialize_aot_33640.php');
    }

    public function testHelperWired(): void
    {
        $root = dirname(__DIR__, 2);
        $unser = (string) file_get_contents($root.'/lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('compileUnserializeRestore', $unser);
        $this->assertStringContainsString('splfixedarray', $unser);
        $this->assertStringContainsString('#33640', $unser);
        $helper = (string) file_get_contents($root.'/lib/VM/SplFixedArrayJitHelper.php');
        $this->assertStringContainsString('compileUnserializeRestore', $helper);
        $nested = (string) file_get_contents($root.'/ext/standard/UnserializeSplFixedArrayNestedJitHelper.php');
        $this->assertStringContainsString('restoreInto', $nested);
        $nullAt = (string) file_get_contents($root.'/ext/standard/phpc_native_ht_set_null_at.php');
        $this->assertStringContainsString('phpc_native_ht_set_null_at', $nullAt);
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
        $bin = sys_get_temp_dir().'/sfa_unser_33640_'.getmypid().'_'.md5($src);
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
