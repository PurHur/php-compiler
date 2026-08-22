<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: typed nested Ack(m-1, Ack(m, n-1)) must not SIGSEGV (#23472).
 *
 * Root cause: JitValueBox::alloc null-inited the __value__ type tag in the allocating
 * CFG arm. Entry allocas are function-wide; freeDeadVariables on sibling return paths
 * valueDelref'd uninitialized stack → intermittent SIGSEGV (~15–25/100 on Ack(3,8)).
 *
 * @see php-src Zend/zend_execute.c (typed recursion / zval lifetime)
 *
 * @group llvm
 * @group aot
 */
final class Issue23472TypedAckNestedAotTest extends TestCase
{
    public function testAotTypedAck38Repeated(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/benchmarks/Ack(3,8).php';
        $bin = sys_get_temp_dir().'/phpc_issue_23472_ack38_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 30; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("2045\n", implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
