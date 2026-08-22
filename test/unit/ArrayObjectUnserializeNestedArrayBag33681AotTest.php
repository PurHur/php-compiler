<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: unserialize(ArrayObject) restores nested array bag values (#33681).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectUnserializeNestedArrayBag33681AotTest extends TestCase
{
    public function testNestedArrayBagMatchesZend(): void
    {
        $src = __DIR__.'/../repro/arrayobject_unserialize_nested_array_bag.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertStringContainsString('[1,2]', $aot);
        $this->assertSame($zend, $aot);
    }

    public function testFillHelperAcceptsNestedArray(): void
    {
        $fill = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/UnserializeSplArrayFillNestedJitHelper.php'
        );
        $this->assertStringContainsString('phpc_native_ht_set_string_key_ht(', $fill);
        $this->assertStringContainsString('phpc_native_ht_alloc(', $fill);
        $this->assertStringContainsString('#33681', $fill);
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
        $bin = sys_get_temp_dir().'/ao_nest_33681_'.getmypid().'_'.md5($src);
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
