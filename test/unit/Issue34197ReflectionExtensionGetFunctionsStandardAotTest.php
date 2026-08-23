<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionExtension::getFunctions('standard') includes is_* and strptime (#34197).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionExtension_getFunctions
 * @see \PHPCompiler\JIT\Builtin\ReflectionExtensionGetFunctionsRuntime
 *
 * @group llvm
 * @group aot
 */
final class Issue34197ReflectionExtensionGetFunctionsStandardAotTest extends TestCase
{
    private const EXPECT = <<<'TXT'
count=532
is_array=yes
is_bool=yes
is_double=yes
is_float=yes
is_int=yes
is_integer=yes
is_long=yes
is_null=yes
is_object=yes
is_string=yes
strptime=yes
TXT;

    public function testAotStandardGetFunctionsMatchesZend(): void
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
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = trim(implode("\n", $runOut));
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame(self::EXPECT, $joined);
        } finally {
            @unlink($bin);
        }
    }

    public function testZendStandardGetFunctionsBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34197_reflection_extension_getfunctions_standard_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }

    public function testVmStandardGetFunctionsMatchesZend(): void
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
}
