<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: array callable ['Class','method']() matches Zend (#32299).
 *
 * @see php-src Zend/zend_execute.c ZEND_INIT_DYNAMIC_CALL
 *
 * @group llvm
 * @group aot
 */
final class ArrayCallableStatic32299AotTest extends TestCase
{
    private const EXPECT = "U|U|U";

    public function testVmArrayCallableStaticMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32299_array_callable_static.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32299_array_callable_static.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testResolverFindsClassAndMethodSlots(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32299_array_callable_static.php'
        );
        $this->assertNotFalse($code);
        $entry = $runtime->parseAndCompile($code, 'issue_32299_array_callable_static.php');
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
                $slots = VM\VmBoundMethodCallable::resolveStaticArrayCallableSlots($block, (int) $op->arg1);
                if (null === $slots) {
                    continue;
                }
                $found = true;
                $this->assertSame('C', $block->constants[$slots[0]]->toString());
                $this->assertSame('m', $block->constants[$slots[1]]->toString());
            }
            foreach ($block->blocks as $child) {
                $queue[] = $child;
            }
        }
        $this->assertTrue($found, 'expected compile-time [Class, method] slots on FUNCCALL_INIT');
    }

    public function testAotArrayCallableStaticMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32299_array_callable_static.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32299_cb_'.getmypid().'.bin';
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
