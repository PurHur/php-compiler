<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplObjectStorage serialize/unserialize bag via `__spl_ht` objKeys (#33876).
 *
 * @group llvm
 * @group aot
 */
final class SplObjectStorageSerialize33876AotTest extends TestCase
{
    public function testSerializeMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_sos_serialize_roundtrip.php');
    }

    public function testUnserializeRestoresCountAndCurrent(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_sos_unserialize_roundtrip.php');
    }

    public function testSosSerializePathWired(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/JitSerialize.php');
        $this->assertStringContainsString('SplObjectStorage', $src);
        $this->assertStringContainsString('#33876', $src);
        $helper = (string) file_get_contents(
            $root.'/ext/standard/SerializeSplObjectStorageNestedJitHelper.php'
        );
        $this->assertStringContainsString('encodeWire', $helper);
        $jit = (string) file_get_contents($root.'/lib/VM/SplObjectStorageJitHelper.php');
        $this->assertStringContainsString('compileSerialize', $jit);
        $this->assertStringContainsString('compileUnserializeRestore', $jit);
        $su = (string) file_get_contents($root.'/lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('splobjectstorage', $su);
        $this->assertStringContainsString('#33876', $su);
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
        $bin = sys_get_temp_dir().'/ao_sos_33876_'.getmypid().'_'.md5($src);
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
