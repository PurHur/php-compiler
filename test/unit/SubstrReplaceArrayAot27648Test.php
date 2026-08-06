<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for substr_replace() array $string (#27648).
 *
 * @group llvm
 * @group aot
 */
final class SubstrReplaceArrayAot27648Test extends TestCase
{
    public function testAotArrayStringOperandMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_substr_replace_array_27648.php';
        $bin = sys_get_temp_dir().'/phpc_substr_replace_27648_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $want = "aX,cX keys=0,1\nabYZf,12YZ\n";
        try {
            for ($i = 0; $i < 5; ++$i) {
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

    public function testCallSitesWireJitSubstrReplaceArray(): void
    {
        $builtin = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/substr_replace.php'
        );
        $this->assertStringContainsString('JitSubstrReplaceArray::invoke', $builtin);
        $this->assertStringNotContainsString(
            'array string operand is not supported in this compiler build',
            $builtin
        );
        $llvm = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitSubstrReplaceArray.php'
        );
        $this->assertStringContainsString('JitSubstrReplace::replace', $llvm);
        $this->assertStringContainsString('__hashtable__setStringAt', $llvm);
    }
}
