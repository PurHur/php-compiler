<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: unserialize(ArrayObject) restores float/bool/null bag values (#33670).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectUnserializeFloatBoolBag33670AotTest extends TestCase
{
    public function testFloatBoolNullBagMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/arrayobject_unserialize_float_bool_bag.php');
    }

    public function testFillHelperAcceptsFloatBoolNull(): void
    {
        $fill = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/UnserializeSplArrayFillNestedJitHelper.php'
        );
        $this->assertStringContainsString('phpc_native_ht_set_string_key_double(', $fill);
        $this->assertStringContainsString('phpc_native_ht_set_string_key_bool(', $fill);
        $this->assertStringContainsString('phpc_native_ht_set_string_key_null(', $fill);
        $this->assertStringContainsString('#33670', $fill);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
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
        $bin = sys_get_temp_dir().'/ao_fb_33670_'.getmypid().'_'.md5($src);
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
