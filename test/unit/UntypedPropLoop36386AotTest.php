<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: untyped property write/inc loops must not SEGV (#36386).
 *
 * php-src: Zend/zend_object_handlers.c zend_std_write_property.
 *
 * @group llvm
 * @group aot
 */
final class UntypedPropLoop36386AotTest extends TestCase
{
    public function testAotUntypedPropAssignAndIncSurvive1M(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36386_untyped_prop_loop.php';
        $bin = sys_get_temp_dir().'/phpc_issue_36386_prop_'.getmypid().'.bin';
        $cache = sys_get_temp_dir().'/phpc_issue_36386_hcache_'.getmypid();
        @mkdir($cache, 0777, true);
        // Fresh helper cache: shared caches can mask the mid-block alloca SEGV.
        // Do not set HELPER_RUNTIME_O=0 — NestedJIT property stores SEGV even at 1k.
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache).' '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("1000000\n1000000\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
            if (is_dir($cache)) {
                foreach (glob($cache.'/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($cache);
            }
        }
    }

    public function testWriteFetchUsesEntryAllocaNotMidBlock(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Builtin/Type/ObjectInstancePropertyLlvm.php'
        );
        $this->assertStringContainsString('entryAlloca($context, $valueType)', $src);
        $this->assertStringContainsString('#36386', $src);
        $this->assertStringContainsString('if ($forWrite)', $src);
        $this->assertStringContainsString('borrowedValueEntry', $src);
        // Mid-block alloca on the write path is the stack-overflow defect.
        $this->assertStringNotContainsString(
            "if (\$forWrite) {\n                        \$storage = \$context->builder->alloca(\$valueType);",
            $src
        );
    }
}
