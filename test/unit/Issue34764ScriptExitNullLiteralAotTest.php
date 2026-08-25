<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: exit(null)/die(null) compile — isNullConstant short-circuit (#34764).
 *
 * @see php-src Zend/zend_builtin_functions.c ZEND_FUNCTION(exit)
 *
 * @group llvm
 * @group aot
 */
final class Issue34764ScriptExitNullLiteralAotTest extends TestCase
{
    public function testVmExitNullSilent(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34764_exit_null_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'issue_34764_exit_null_aot.php'));
        } catch (\Throwable) {
            // exit may abort the VM harness; stdout should still be empty on 8.2.
        }
        $out = (string) ob_get_clean();
        $this->assertSame('', $out);
    }

    public function testAotExitNullCompilesAndMatchesZend(): void
    {
        $this->assertAotMatches('issue_34764_exit_null_aot.php', '', 0);
    }

    public function testAotDieNullCompilesAndMatchesZend(): void
    {
        $this->assertAotMatches('issue_34764_die_null_aot.php', '', 0);
    }

    private function assertAotMatches(string $repro, string $expectedOut, int $expectedRc): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$repro;
        $bin = sys_get_temp_dir().'/phpc_34764_'.getmypid().'_'.md5($repro).'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame($expectedRc, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame($expectedOut, implode("\n", $runOut));
            }
        } finally {
            @unlink($bin);
        }
    }
}
