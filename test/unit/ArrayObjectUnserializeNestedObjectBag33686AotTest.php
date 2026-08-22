<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: unserialize(ArrayObject) restores nested object bag values (#33686).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectUnserializeNestedObjectBag33686AotTest extends TestCase
{
    public function testNestedObjectBagMatchesZend(): void
    {
        $src = __DIR__.'/../repro/arrayobject_unserialize_nested_object_bag.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertStringContainsString('"a":1', $aot);
        $this->assertSame($zend, $aot);
    }

    public function testFillHelperAcceptsNestedObject(): void
    {
        $root = dirname(__DIR__, 2);
        $fill = (string) file_get_contents(
            $root.'/ext/standard/UnserializeSplArrayFillNestedJitHelper.php'
        );
        $this->assertStringContainsString('phpc_native_ht_set_string_key_stdclass_from_ht(', $fill);
        $this->assertStringContainsString('#33686', $fill);
        $native = (string) file_get_contents(
            $root.'/ext/standard/phpc_native_ht_set_string_key_stdclass_from_ht.php'
        );
        $this->assertStringContainsString('defineProperty', $native);
        $helper = (string) file_get_contents($root.'/lib/VM/ArrayObjectJitHelper.php');
        $this->assertStringContainsString('phpc_native_ht_set_string_key_stdclass_from_ht', $helper);
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
        $bin = sys_get_temp_dir().'/ao_nest_obj_33686_'.getmypid().'_'.md5($src);
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
