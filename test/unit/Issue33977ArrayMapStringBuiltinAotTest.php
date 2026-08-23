<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_map() string builtins outside the typed allowlist (#33977).
 *
 * @see php-src ext/standard/array.c php_array_map()
 * @see \PHPCompiler\JIT\ArrayMapLlvm::mapBuiltin
 *
 * @group llvm
 * @group aot
 */
final class Issue33977ArrayMapStringBuiltinAotTest extends TestCase
{
    private const EXPECTED = "abs=1,2,3\nupper=A,B\nunlink=no\n";

    public function testVmArrayMapAbsUnlink(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_33977_array_map_string_builtin_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33977_array_map_string_builtin_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotArrayMapAbsUnlink(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33977_array_map_string_builtin_aot.php';
        $bin = sys_get_temp_dir().'/phpc_33977_amap_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testArrayMapLlvmUsesGenericBuiltinFallback(): void
    {
        $llvm = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/ArrayMapLlvm.php'
        );
        $this->assertStringContainsString('array_map_generic', $llvm);
        $this->assertStringContainsString('coerceToValuePtrForStore', $llvm);
        $this->assertStringContainsString('#33977', $llvm);
        $this->assertStringNotContainsString(
            'array_map() callback is not supported by the JIT compiler in this build',
            $llvm
        );
    }
}
