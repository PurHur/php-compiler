<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: exit(float)/die(bool) link __phpc_ob_echo_* via ObOutputRuntime (#34756).
 *
 * @see php-src Zend/zend_builtin_functions.c ZEND_FUNCTION(exit)
 *
 * @group llvm
 * @group aot
 */
final class Issue34756ScriptExitObEchoAotTest extends TestCase
{
    public function testVmExitFloat(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34756_exit_float_bool_ob_echo_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'issue_34756_exit_float_bool_ob_echo_aot.php'));
        } catch (\Throwable) {
            // exit() may throw/abort in VM harness; capture printed status text.
        }
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('1.5', $out);
    }

    public function testAotExitFloatLinksAndMatchesZend(): void
    {
        $this->assertAotMatches(
            'issue_34756_exit_float_bool_ob_echo_aot.php',
            '1.5',
            0
        );
    }

    public function testAotDieBoolLinksAndMatchesZend(): void
    {
        $this->assertAotMatches(
            'issue_34756_die_bool_ob_echo_aot.php',
            '1',
            0
        );
    }

    private function assertAotMatches(string $repro, string $expectedOut, int $expectedRc): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$repro;
        $bin = sys_get_temp_dir().'/phpc_34756_'.getmypid().'_'.md5($repro).'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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
