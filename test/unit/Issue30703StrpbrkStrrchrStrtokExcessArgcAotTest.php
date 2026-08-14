<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: strpbrk/strrchr/strtok excess argc → ArgumentCountError (#30703).
 *
 * php-src: ext/standard/string.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30703StrpbrkStrrchrStrtokExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30703_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30703_ex_'.getmypid().'.bin';
        file_put_contents($src, file_get_contents($root.'/test/repro/issue_30703_strpbrk_strrchr_strtok_excess_argc_aot.php'));
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
                "strpbrk:ArgumentCountError:strpbrk() expects exactly 2 arguments, 3 given\n"
                ."strrchr:ArgumentCountError:strrchr() expects exactly 2 arguments, 3 given\n"
                ."strtok:ArgumentCountError:strtok() expects at most 2 arguments, 3 given\n"
                ."ok:'bc'\n",
                implode("\n", $runOut)."\n"
            );
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
