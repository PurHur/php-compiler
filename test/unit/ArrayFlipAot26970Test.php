<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for array_flip() (#26970).
 *
 * NestedJIT must lower HashTable::update via HashTableWriteNested — not resolve
 * onto ArrayFlipJitHelper::update.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_flip)
 *
 * @group llvm
 * @group aot
 */
final class ArrayFlipAot26970Test extends TestCase
{
    public function testAotArrayFlipBuildsAndPrintsKeys(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_array_flip_26970_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo implode(',', array_flip(['a', 'b'])), "\n";
echo implode(',', array_flip(['x' => 10, 'y' => 20])), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_array_flip_26970_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("0,1\nx,y\n", implode("\n", $runOut)."\n");
            }
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($src);
            @unlink($bin);
        }
    }

    public function testNestedHashTableUpdateIsRegistered(): void
    {
        $nested = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/NestedVmHashTableMethodLlvm.php'
        );
        $this->assertStringContainsString("'update' => Call\\HashTableWriteNested::class", $nested);
        $write = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Call/HashTableWriteNested.php'
        );
        $this->assertStringContainsString("case 'update':", $write);
    }

    public function testArrayFlipRuntimeUsesCallSiteLlvm(): void
    {
        $runtime = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayFlipRuntime.php'
        );
        $this->assertStringContainsString('ArrayFlipLlvm::flipHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
    }
}
