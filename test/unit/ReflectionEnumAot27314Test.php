<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT guard: ReflectionEnum getName/isBacked/getCases (#27314).
 *
 * Enum-case `$name` propertyFetch runtime dispatch aborted for ReflectionEnum when
 * any enum was declared in the unit (fallback called abort()). Also wire getCases()
 * JIT Call + markHasConstructor for thin AOT construct.
 *
 * php-src: ext/reflection/php_reflection.c — ReflectionEnum
 *
 * @group llvm
 * @group aot
 */
final class ReflectionEnumAot27314Test extends TestCase
{
    public function testAotReflectionEnumNameBackedCases(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_reflection_enum_name_backed_cases.php';
        $bin = sys_get_temp_dir().'/phpc_reflenum_27314_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $expected));
        $want = implode("\n", $expected)."\n";
        $this->assertSame("E|y|2\n", $want);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($want, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
