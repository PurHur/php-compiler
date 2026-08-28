<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionFunction::isVariadic() must link — reuse ReflectionNative decl (#23593 / #22045).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionFunctionAbstract_isVariadic
 * @see \PHPCompiler\JIT\Builtin\ReflectionFunctionVariadicLookupRuntime
 *
 * @group llvm
 * @group aot
 */
final class Issue23593ReflectionFunctionVariadicAotTest extends TestCase
{
    public function testVariadicLookupReusesReflectionNativeDeclaration(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionFunctionVariadicLookupRuntime.php'
        );
        $this->assertStringContainsString('Reuse declaration from ReflectionNative', $source);
        $this->assertStringContainsString('null !== $probe', $source);
    }

    public function testVariadicLoweringSeedsInternalFunctionsFromBuiltinParamNames(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionFunctionVariadicLowering.php'
        );
        $this->assertStringContainsString('variadicInternalFunctionNames', $source);
        $this->assertContains('array_diff', BuiltinParamNames::variadicInternalFunctionNames());
    }

    public function testAotArrayDiffIsVariadicLinksAndMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_23593_reflection_function_is_variadic_aot.php';
        $bin = sys_get_temp_dir().'/phpc_23593_refl_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $vmCmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($vmCmd, $vmOut, $vmRc);
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $expected = trim(implode("\n", $vmOut));

        try {
            exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aotOut));
            $this->assertSame($expected, trim(implode("\n", $aotOut)));
        } finally {
            @unlink($bin);
        }
    }

    public function testMaintainerGapReflectionNamesVmMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_array_diff_intersect_reflection_names.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertStringContainsString('array_diff [array,arrays*]', $joined);
        $this->assertStringContainsString('ArgumentCountError: array_diff() does not accept unknown named parameters', $joined);
    }
}
