<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: nullsafe ?-> property fetch must verify and match Zend (#32988).
 *
 * @see php-src Zend/zend_compile.c nullsafe / ZEND_JMP_NULL
 *
 * @group llvm
 * @group aot
 */
final class NullsafePropDominatesAot32988Test extends TestCase
{
    private const EXPECTED = "NULL\n1\nx\n";

    public function testVmNullsafeProp(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32988_nullsafe_prop_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32988_nullsafe_prop_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotNullsafePropDominates(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32988_nullsafe_prop_aot.php';
        $bin = sys_get_temp_dir().'/phpc_ns_32988_'.getmypid();
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($compile.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
    }
}
