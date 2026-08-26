<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: packed [$obj,'method']() / call_user_func([$obj,'method']) match Zend (#35094).
 *
 * @see php-src Zend/zend_execute.c ZEND_INIT_DYNAMIC_CALL
 * @see peer #4040 tryInitBoundMethodFccDirect / #35090 static call_user_func
 *
 * @group llvm
 * @group aot
 */
final class BoundMethodPackedArrayCallable35094AotTest extends TestCase
{
    private const EXPECT = "6\n6\n6";

    public function testVmBoundMethodPackedArrayCallableMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/aot_bound_method_array_callable.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_bound_method_array_callable.php'));
        $out = rtrim((string) ob_get_clean(), "\n");
        $this->assertSame(self::EXPECT, $out);
    }

    public function testResolveMethodLcHandlesPackedNullKeys(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/aot_bound_method_array_callable.php'
        );
        $this->assertNotFalse($code);
        $entry = $runtime->parseAndCompile($code, 'aot_bound_method_array_callable.php');
        $found = false;
        $queue = [$entry];
        $seen = [];
        while ([] !== $queue) {
            $block = array_shift($queue);
            $id = spl_object_id($block);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT !== $op->type || null === $op->arg1) {
                    continue;
                }
                $methodLc = VM\VmBoundMethodCallable::resolveMethodLcFromCalleeSlot($block, (int) $op->arg1);
                if ('f' === $methodLc) {
                    $found = true;
                    break 2;
                }
            }
            foreach ($block->blocks as $child) {
                $queue[] = $child;
            }
        }
        $this->assertTrue($found, 'resolveMethodLcFromCalleeSlot must see packed [$o,"f"] method');
    }

    public function testAotBoundMethodPackedArrayCallableMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_bound_method_array_callable.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35094_bound_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECT, implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
