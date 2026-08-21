<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: unserialize(ArrayObject/ArrayIterator) restores __spl_ht bag (#33636).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectUnserialize33636AotTest extends TestCase
{
    public function testUnserializeMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/arrayobject_unserialize_aot_33636.php');
    }

    public function testHelperWired(): void
    {
        $root = dirname(__DIR__, 2);
        $unser = (string) file_get_contents($root.'/lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('compileUnserializeFromWire', $unser);
        $this->assertStringContainsString('#33636', $unser);
        $helper = (string) file_get_contents($root.'/lib/VM/ArrayObjectJitHelper.php');
        $this->assertStringContainsString('compileUnserializeFromWire', $helper);
        $bag = (string) file_get_contents($root.'/ext/standard/UnserializeSplArrayNestedJitHelper.php');
        $this->assertStringContainsString('restoreInto', $bag);
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
        $bin = sys_get_temp_dir().'/ao_unser_33636_'.getmypid().'_'.md5($src);
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
