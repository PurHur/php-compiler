<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\LlvmToolchain;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Instance property ??= stores and readback matches Zend (#33748).
 *
 * @see php-src Zend/zend_vm_def.h ZEND_COALESCE / ZEND_ASSIGN_OBJ_OP
 * @see php-src Zend/zend_execute.c object assign
 */
final class InstancePropertyCoalesceAssign33748AotTest extends TestCase
{
    private const EXPECTED = "5\n5\n7\n3\n";

    /**
     * @covers issue #33748
     */
    public function testVmInstancePropertyCoalesceAssign(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_instance_prop_coalesce_assign.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_instance_prop_coalesce_assign.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @covers issue #33748
     *
     * @group llvm
     * @group aot
     */
    public function testAotInstancePropertyCoalesceAssignStableAcrossRuns(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_instance_prop_coalesce_assign.php';
        $bin = sys_get_temp_dir().'/phpc_33748_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
        $this->assertFileExists($bin);

        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(
                    ['5', '5', '7', '3'],
                    $runOut,
                    'run '.$i
                );
            }
        } finally {
            @unlink($bin);
        }
    }
}
