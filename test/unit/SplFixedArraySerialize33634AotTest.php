<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize(SplFixedArray) encodes __spl_ht elements (#33634).
 *
 * @group llvm
 * @group aot
 */
final class SplFixedArraySerialize33634AotTest extends TestCase
{
    public function testSerializeMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/splfixedarray_serialize_aot_33634.php');
    }

    public function testSplFixedArraySerializePathWired(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/JitSerialize.php');
        $this->assertStringContainsString('tryEncodeSplHtObject', $src);
        $this->assertStringContainsString('#33634', $src);
        $helper = (string) file_get_contents($root.'/ext/standard/SerializeSplFixedArrayNestedJitHelper.php');
        $this->assertStringContainsString('encodeWire', $helper);
        $this->assertStringContainsString('#33634', $helper);
        $jit = (string) file_get_contents($root.'/lib/VM/SplFixedArrayJitHelper.php');
        $this->assertStringContainsString('compileSerialize', $jit);
        $this->assertStringContainsString('#33634', $jit);
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
        $bin = sys_get_temp_dir().'/ao_sfa_33634_'.getmypid().'_'.md5($src);
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
