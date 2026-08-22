<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: consecutive Ack in {main} must not SIGSEGV (#23472).
 *
 * Root cause: echoing a FuncCall temp in {main} without a stable CV, plus stale outbound-call
 * state when a literal echo sits between two top-level calls. Repro uses named assigns (both
 * calls before any echo); direct `echo Ack(); echo "\\n"; echo Ack();` stays in
 * issue_23472_literal_echo_between.php.
 *
 * @see php-src Zend/zend_execute.c (ZEND_ECHO + call result materialization)
 *
 * @group llvm
 * @group aot
 */
final class Issue23472AckTwiceEchoAotTest extends TestCase
{
    private const EXPECTED = "61\n61\n";

    public function testVmAckTwiceEcho(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_23472_ack_twice_echo.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_23472_ack_twice_echo.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotAckTwiceEchoRepeated(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_23472_ack_twice_echo.php';
        $bin = sys_get_temp_dir().'/phpc_issue_23472_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
