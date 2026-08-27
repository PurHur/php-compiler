<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: boxed numeric-string <=> int (#35317 leftover of #35313).
 *
 * @see php-src Zend/zend_operators.c compare_function
 *
 * @group llvm
 * @group aot
 */
final class BoxedStringSpaceship35317AotTest extends TestCase
{
    private const EXPECT = "int(0)\nint(0)\nint(0)\nint(0)\n";

    public function testSpaceshipStringToNativeLongWired(): void
    {
        $vm = (string) file_get_contents(__DIR__.'/../../lib/VM/VmValueCompare.php');
        $this->assertStringContainsString('spaceshipStringToNativeLong', $vm);
        $this->assertStringContainsString('#35317', $vm);
        $unlike = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitUnlikeCompare.php');
        $this->assertStringContainsString('#35317', $unlike);
        $this->assertStringContainsString('_sp_str_long', $unlike);
    }

    public function testVmBoxedStringSpaceshipMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_35317_boxed_string_spaceship.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35317_boxed_string_spaceship.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotBoxedStringSpaceshipMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35317_boxed_string_spaceship.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35317_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
