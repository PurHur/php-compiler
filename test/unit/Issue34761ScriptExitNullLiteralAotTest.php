<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: exit(null)/die(null) literals — TYPE_VALUE+isNullConstant must not emitBoxed (#34761).
 *
 * @see php-src Zend/zend_builtin_functions.c ZEND_FUNCTION(exit)
 *
 * @group llvm
 * @group aot
 */
final class Issue34761ScriptExitNullLiteralAotTest extends TestCase
{
    public function testVmExitNullLiteral(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34761_exit_null_literal_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'issue_34761_exit_null_literal_aot.php'));
        } catch (\Throwable) {
            // exit may abort the VM harness; stdout must still be empty.
        }
        $out = (string) ob_get_clean();
        $this->assertSame('', $out);
    }

    public function testAotExitNullLiteralMatchesZend(): void
    {
        $this->assertAotMatches('issue_34761_exit_null_literal_aot.php', '', 0);
    }

    public function testAotDieNullLiteralMatchesZend(): void
    {
        $this->assertAotMatches('issue_34761_die_null_literal_aot.php', '', 0);
    }

    public function testAotExitNullAssignedControl(): void
    {
        $this->assertAotMatches('issue_34761_exit_null_assigned_aot.php', '', 0);
    }

    private function assertAotMatches(string $repro, string $expectedOut, int $expectedRc): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$repro;
        $bin = sys_get_temp_dir().'/phpc_34761_'.getmypid().'_'.md5($repro).'.bin';
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
