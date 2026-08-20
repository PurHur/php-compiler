<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: local string dim write + function-static array dim (#32806 / #32800).
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_string_offset / ZEND_ASSIGN_DIM
 *
 * @group llvm
 * @group aot
 */
final class LocalStringDimAssign32806AotTest extends TestCase
{
    private const EXPECTED = "aZc\nstring\ny\ny\nxy\nxyy\n";

    public function testVmLocalStringDimAndFunctionStatic(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32806_local_string_dim_and_function_static.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32806_local_string_dim_and_function_static.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotLocalStringDimAndFunctionStatic(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32806_local_string_dim_and_function_static.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32806_lsda_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
