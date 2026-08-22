<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize(ArrayObject) float/bool bag must use JIT tags (#33687).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectSerializeFloatBool33687AotTest extends TestCase
{
    public function testFloatBoolBagSerializeMatchesZend(): void
    {
        $src = __DIR__.'/../repro/arrayobject_serialize_float_bool_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertStringContainsString('d:1.5;', $aot);
        $this->assertStringContainsString('b:1;', $aot);
        $this->assertStringContainsString('b:0;', $aot);
        $this->assertStringNotContainsString('i:0;b:1;i:1;d:0;', $aot);
        $this->assertSame($zend, $aot);
    }

    public function testEncodeHelperDispatchesJitBoolBeforeDouble(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/SerializeSplArrayNestedJitHelper.php'
        );
        $this->assertStringContainsString('#33687', $src);
        $this->assertMatchesRegularExpression(
            '/2 === \$t.*?toBool\(\).*?b:1;.*?b:0;.*?3 === \$t.*?toFloat\(\)/s',
            $src
        );
        $toBool = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Call/VariableToBool.php'
        );
        $this->assertStringContainsString('readBoolByte', $toBool);
        $this->assertStringContainsString('TYPE_NATIVE_BOOL', $toBool);
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
        $bin = sys_get_temp_dir().'/ao_ser_33687_'.getmypid().'_'.md5($src);
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
