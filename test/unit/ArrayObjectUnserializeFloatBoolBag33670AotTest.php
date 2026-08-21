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
        $src = __DIR__.'/../repro/arrayobject_unserialize_float_bool_bag.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        // Guard against __value__readDouble→0.0 on NestedJIT string boxes (#33670 / #32325).
        $this->assertStringContainsString('1.5', $aot);
        $this->assertSame($zend, $aot);
    }

    public function testDoubleFromVarUsesValueBoxStrtod(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ParseStrNativeOpsJit.php'
        );
        $this->assertStringContainsString('valueBoxToDouble', $src);
        $this->assertStringNotContainsString(
            'extractDoubleFromHelperResult($context, $raw)',
            $src
        );
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

    private function runPhp(string $src): string
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/ao_fbb_33670_'.getmypid().'_'.md5($src);
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
