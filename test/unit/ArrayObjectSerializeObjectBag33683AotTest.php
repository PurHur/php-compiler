<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize(ArrayObject) with object bag values must match Zend (#33683).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectSerializeObjectBag33683AotTest extends TestCase
{
    public function testObjectBagSerializeMatchesZend(): void
    {
        $src = __DIR__.'/../repro/arrayobject_serialize_object_bag.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertStringContainsString('O:8:"stdClass"', $aot);
        $this->assertStringContainsString('s:1:"a";i:1;', $aot);
        $this->assertSame($zend, $aot);
    }

    public function testEmptyStdClassBagSerializeMatchesZend(): void
    {
        $src = __DIR__.'/../repro/arrayobject_serialize_empty_stdclass_bag.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertStringContainsString('O:8:"stdClass":0:{}', $aot);
        $this->assertSame($zend, $aot);
    }

    public function testEncodeHelperBranchesTypeObject(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/SerializeSplArrayNestedJitHelper.php'
        );
        $this->assertStringContainsString('5 === $t', $src);
        $this->assertStringContainsString('\\serialize($val)', $src);
        $this->assertStringContainsString('#33683', $src);
    }

    private function runPhp(string $src): string
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/ao_ser_33683_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
