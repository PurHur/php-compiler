<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: instance `$o->v ??=` must not fail module verify (#32880).
 *
 * @see php-src Zend/zend_compile.c ZEND_COALESCE / ASSIGN_OBJ_OP
 * @see php-src Zend/zend_execute.c ZEND_ASSIGN_OBJ_OP
 *
 * @group llvm
 * @group aot
 */
final class InstanceNullCoalesceAssign32880AotTest extends TestCase
{
    private const EXPECTED = "int(7)\nint(0)\nint(3)\n";

    public function testVmInstanceNullCoalesceAssign(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_32880_instance_nullcoalesce_assign.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32880_instance_nullcoalesce_assign.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotInstanceNullCoalesceAssign(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32880_instance_nullcoalesce_assign.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32880_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
