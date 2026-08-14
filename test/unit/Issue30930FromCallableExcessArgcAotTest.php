<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Closure::fromCallable excess argc (#30930).
 *
 * @group llvm
 * @group aot
 */
final class Issue30930FromCallableExcessArgcAotTest extends TestCase
{
    public function testAotFromCallableExcessArgc(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30930_aot_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30930_aot_'.getmypid().'.bin';
        file_put_contents($src, file_get_contents(__DIR__.'/../repro/issue_30930_fromcallable_excess_argc.php'));
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $text = implode("\n", $runOut)."\n";
            $this->assertStringContainsString(
                "ArgumentCountError: Closure::fromCallable() expects exactly 1 argument, 2 given\n",
                $text
            );
            $this->assertStringContainsString(
                "ArgumentCountError: Closure::fromCallable() expects exactly 1 argument, 0 given\n",
                $text
            );
            $this->assertStringContainsString("ok=2\n", $text);
            $this->assertStringNotContainsString('OK:', $text);
            $this->assertStringNotContainsString('LogicException', $text);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
