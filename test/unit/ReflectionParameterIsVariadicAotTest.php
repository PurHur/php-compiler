<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionParameter::isVariadic() must not segfault on shutdown (#24461).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionParameter_isVariadic
 * @see \PHPCompiler\JIT\Builtin\ParamVariadicLookupRuntime
 *
 * @group llvm
 * @group aot
 */
final class ReflectionParameterIsVariadicAotTest extends TestCase
{
    public function testVariadicLookupDeclaresAbiInReflectionNative(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionNative.php'
        );
        $this->assertStringContainsString('__compiler_param_func_is_variadic', $source);
        $this->assertStringContainsString('__compiler_param_method_is_variadic', $source);
    }

    public function testAotReflectionParameterIsVariadicMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_reflection_parameter_is_variadic_aot.php';
        $bin = sys_get_temp_dir().'/phpc_refl_param_var_'.getmypid().'.bin';
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
            for ($i = 0; $i < 3; ++$i) {
                $aotOut = [];
                exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
                $this->assertSame(0, $aotRc, 'run '.($i + 1).': '.implode("\n", $aotOut));
                $this->assertSame($expected, trim(implode("\n", $aotOut)), 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
