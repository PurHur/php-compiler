<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: loose == in switch with variable case after prior switch chains (#33800).
 *
 * php-src: Zend/zend_operators.c compare_function for IS_STRING pairs
 *
 * @group llvm
 * @group aot
 */
final class SwitchSuperglobalVarCase33800AotTest extends TestCase
{
    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_33800_switch_superglobal_string_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33800_switch_superglobal_string_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('cross_switch=home', $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33800_switch_superglobal_string_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33800_switch_'.getmypid().'.bin';
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
            $this->assertStringContainsString('cross_switch=home', implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
