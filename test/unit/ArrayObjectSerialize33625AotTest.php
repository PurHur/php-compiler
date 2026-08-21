<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize(ArrayObject/ArrayIterator) encodes __spl_ht + __flags bag (#33625).
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

    public function testSplArraySerializePathWired(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/JitSerialize.php');
        $this->assertStringContainsString('tryEncodeSplArrayObjectBag', $src);
        $this->assertStringContainsString('#33625', $src);
        $this->assertStringContainsString('splBackingHashtable', $src);
        $this->assertStringContainsString("lookup('ArrayObject')", $src);
        $helper = (string) file_get_contents($root.'/ext/standard/SerializeSplArrayObjectNestedJitHelper.php');
        $this->assertStringContainsString('formatBag', $helper);
        $this->assertStringContainsString('#33625', $helper);
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
