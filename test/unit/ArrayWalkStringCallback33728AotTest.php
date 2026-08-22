<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_walk string callbacks pass __string__* not cstr (#33728).
 *
 * @group llvm
 * @group aot
 */
final class ArrayWalkStringCallback33728AotTest extends TestCase
{
    public function testStringCallbacksMatchZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_array_walk_string_callback_aot.php');
    }

    public function testRuntimeRoutesStringViaArrayWalkLlvm(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/JIT/Builtin/ArrayWalkRuntime.php');
        $this->assertStringContainsString('ArrayWalkLlvm::walkWithUserFunction', $src);
        $this->assertStringContainsString('ArrayWalkLlvm::walkWithBuiltin', $src);
        $this->assertStringContainsString('#33728', $src);
        $llvm = (string) file_get_contents($root.'/lib/JIT/ArrayWalkLlvm.php');
        $this->assertStringContainsString('walkWithUserFunction', $llvm);
        $this->assertStringContainsString('#33728', $llvm);
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
        $bin = sys_get_temp_dir().'/awalk_33728_'.getmypid().'_'.md5($src);
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
