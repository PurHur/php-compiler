<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionExtension::getFunctions('standard') includes ownership-remapped names (#34197).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionExtension_getFunctions
 * @see \PHPCompiler\JIT\Builtin\ReflectionExtensionGetFunctionsRuntime
 *
 * @group llvm
 * @group aot
 */
final class Issue34197ReflectionExtensionGetFunctionsStandardAotTest extends TestCase
{
    private const EXPECT = 'type=array count=532 is_array=1 is_bool=1 is_double=1 is_float=1 is_int=1 is_integer=1 is_long=1 is_null=1 is_object=1 is_string=1 strptime=1';

    public function testRuntimeCollectsByOwningExtension(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionExtensionGetFunctionsRuntime.php'
        );
        $this->assertStringContainsString('reflectionOwningExtension', $source);
        $this->assertStringNotContainsString('!== $lcExt', $source);
    }

    public function testZendGetFunctionsStandardBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34197_reflection_extension_getfunctions_standard_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }

    public function testVmGetFunctionsStandardMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34197_reflection_extension_getfunctions_standard_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }

    public function testAotGetFunctionsStandardMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34197_reflection_extension_getfunctions_standard_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34197_gen_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $joined = trim(implode("\n", $runOut));
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame(self::EXPECT, $joined);
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testDateReproStillMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34177_reflection_extension_getfunctions_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34197_date_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $expect = 'type=array count=48 strtotime=1';
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($expect, trim(implode("\n", $runOut)));
        } finally {
            @unlink($bin);
        }
    }
}
