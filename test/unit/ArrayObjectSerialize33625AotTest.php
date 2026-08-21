<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize(ArrayObject/ArrayIterator) encodes __spl_ht bag (#33625).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectSerialize33625AotTest extends TestCase
{
    public function testSerializeMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/arrayobject_serialize_aot_33625.php');
    }

    public function testHelperWired(): void
    {
        $root = dirname(__DIR__, 2);
        $ser = (string) file_get_contents($root.'/ext/standard/JitSerialize.php');
        $this->assertStringContainsString('tryEncodeSplArrayObject', $ser);
        $this->assertStringContainsString('#33625', $ser);
        $helper = (string) file_get_contents($root.'/lib/VM/ArrayObjectJitHelper.php');
        $this->assertStringContainsString('compileSerialize', $helper);
        $bag = (string) file_get_contents($root.'/ext/standard/SerializeSplArrayNestedJitHelper.php');
        $this->assertStringContainsString('encodeWire', $bag);
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
        $bin = sys_get_temp_dir().'/ao_ser_33625_'.getmypid().'_'.md5($src);
        // Prefer helper-runtime (peer #32925) — NestedJIT HT path aborts with O=0.
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
