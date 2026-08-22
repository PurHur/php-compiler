<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize(SplFixedArray) float/bool wire matches Zend (#33682).
 *
 * @group llvm
 * @group aot
 */
final class SplFixedArraySerializeFloatBool33682AotTest extends TestCase
{
    public function testSerializeFloatBoolMatchesZend(): void
    {
        $src = __DIR__.'/../repro/splfixedarray_serialize_float_bool_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertStringContainsString('d:1.5;', $aot);
        $this->assertStringContainsString('b:1;', $aot);
        $this->assertStringNotContainsString('i:0;b:1;i:1;d:0;', $aot);
        $this->assertSame($zend, $aot);
    }

    public function testHelpersUseJitBoolDoubleTags(): void
    {
        $root = dirname(__DIR__, 2);
        $sfa = (string) file_get_contents($root.'/ext/standard/SerializeSplFixedArrayNestedJitHelper.php');
        $this->assertStringContainsString('#33682', $sfa);
        $this->assertStringContainsString('2 === $t', $sfa);
        $this->assertStringContainsString('toBool()', $sfa);
        $nested = (string) file_get_contents($root.'/ext/standard/SerializeNestedJitHelper.php');
        $this->assertStringContainsString('#33682', $nested);
        $this->assertStringContainsString('TYPE_NATIVE_BOOL', $nested);
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
        $bin = sys_get_temp_dir().'/sfa_ser_33682_'.getmypid().'_'.md5($src);
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
