<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard: token_name() on a runtime token id from token_get_all() (#27278).
 *
 * Master refused non-ConstantInt operands at compile time. Compile-time T_* folding remains;
 * runtime ints use the id→name select-walk (peer PhpToken::getTokenName).
 *
 * php-src: ext/tokenizer/tokenizer.c — PHP_FUNCTION(token_name)
 *
 * @group llvm
 * @group aot
 */
final class TokenNameAot27278Test extends TestCase
{
    public function testAotTokenNameRuntimeArgFromTokenGetAll(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_token_name_runtime_arg.php';
        $bin = sys_get_temp_dir().'/phpc_token_name_27278_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $expected));
        $want = implode("\n", $expected)."\n";
        $this->assertSame("5\nT_ECHO\n", $want);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($want, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($bin);
        }
    }
}
