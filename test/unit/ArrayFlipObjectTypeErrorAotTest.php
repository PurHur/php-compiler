<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_flip() on object operands throws catchable TypeError (#36136).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_flip) Z_PARAM_ARRAY
 *
 * @group llvm
 * @group aot
 */
final class ArrayFlipObjectTypeErrorAotTest extends TestCase
{
    public function testAotArrayFlipObjectTypeErrorMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_array_flip_object_typeerror.php';
        $this->assertAotMatchesZend($src);
    }

    public function testArrayFlipObjectTypeErrorHelperGuardPresent(): void
    {
        $flip = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/array_flip.php');
        $this->assertStringContainsString('ArrayFlipRuntime::flip', $flip);
        $this->assertStringContainsString('JitValueBox::isValueOperand', $flip);
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
        $bin = sys_get_temp_dir().'/array_flip_obj_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
