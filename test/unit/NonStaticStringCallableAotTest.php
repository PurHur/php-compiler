<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: string/array Class::instanceMethod() callables must Error — not static invoke (#31968 group 3).
 *
 * @see php-src Zend/zend_execute_API.c zend_is_callable_ex / zend_closures.c
 *
 * @group llvm
 * @group aot
 */
final class NonStaticStringCallableAotTest extends TestCase
{
    private const EXPECT = "self::priv() = 42\nOK string: Non-static method C::priv() cannot be called statically\nOK array: Non-static method C::priv() cannot be called statically";

    public function testVmNonStaticStringCallableMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/maintainer_gap_string_callable_instance_static.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_string_callable_instance_static.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, rtrim($out));
    }

    public function testAotNonStaticStringCallableMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_string_callable_instance_static.php';
        $bin = sys_get_temp_dir().'/phpc_nonstatic_callable_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, rtrim(implode("\n", $runOut)));
        } finally {
            @unlink($bin);
        }
    }
}
