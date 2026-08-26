<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: isset($s[$i]) / empty($s[$i]) must match Zend for VALUE-boxed int dims (#35039).
 *
 * @see php-src Zend/zend_execute.c zend_isset_dim
 * @see test/repro/aot_isset_string_offset_var.php
 *
 * @group llvm
 * @group aot
 */
final class IssetStringOffsetValueDim35039Test extends TestCase
{
    private const EXPECTED = "lit0=1\nvar0=1\nlit4=1\nvar4=1\nlit5=0\nvar5=0\nloop_n=5\nempty0=0\nempty5=1\n";

    public function testVmIssetEmptyMatchExpected(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_isset_string_offset_var.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_isset_string_offset_var.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotIssetEmptyMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_isset_string_offset_var.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35039_'.getmypid().'.bin';
        $compile = @shell_exec(
            'cd '.escapeshellarg($root).' && php bin/compile.php -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1'
        );
        $this->assertFileExists($bin, 'compile failed: '.(string) $compile);
        $out = (string) shell_exec(escapeshellarg($bin).' 2>&1');
        @unlink($bin);
        $this->assertSame(self::EXPECTED, $out);
    }
}
