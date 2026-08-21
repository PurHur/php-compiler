<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode(ArrayObject/ArrayIterator) encodes __spl_ht (#33619).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectJsonEncode33619AotTest extends TestCase
{
    public function testJsonEncodeMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/arrayobject_json_encode_aot_33619.php');
    }

    public function testSplArrayPathWired(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/JitJsonEncode.php');
        $this->assertStringContainsString('tryEncodeSplArrayObjectStorage', $src);
        $this->assertStringContainsString('#33619', $src);
        $this->assertStringContainsString('splBackingHashtable', $src);
        $this->assertStringContainsString("lookup('ArrayObject')", $src);
        $llvm = (string) file_get_contents($root.'/lib/JIT/JsonEncodeArrayLlvm.php');
        $this->assertStringContainsString('JSON_FORCE_OBJECT=16', $llvm);
        $this->assertStringContainsString('#33619', $llvm);
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
        $bin = sys_get_temp_dir().'/ao_json_33619_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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
