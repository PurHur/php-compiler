<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: class_alias() excess argc → Zend ArgumentCountError (#30783).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias)
 *
 * @group llvm
 * @group aot
 */
final class Issue30783ClassAliasExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30783_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30783_ex_'.getmypid().'.bin';
        file_put_contents($src, file_get_contents($root.'/test/repro/issue_30783_class_alias_excess_argc_aot.php'));
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(
                "excess:ArgumentCountError:class_alias() expects at most 3 arguments, 4 given\n"
                ."short:ArgumentCountError:class_alias() expects at least 2 arguments, 1 given\n"
                ."ok:true\n",
                implode("\n", $runOut)."\n"
            );
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
