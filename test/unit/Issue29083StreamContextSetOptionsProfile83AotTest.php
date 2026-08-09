<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: stream_context_set_options registered on PROFILE=8.3 (#29083).
 *
 * @group llvm
 * @group aot
 */
final class Issue29083StreamContextSetOptionsProfile83AotTest extends TestCase
{
    public function testAotProfile83RoundTrip(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_29083_stream_context_set_options_profile83.php';
        $bin = sys_get_temp_dir().'/phpc_29083_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_PROFILE=8.3 PHP_COMPILER_HELPER_RUNTIME_O=0 '
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
            $this->assertSame(
                "exists=1\nset=1\nmethod=GET\ntimeout=1\n",
                implode("\n", $runOut)."\n"
            );
        } finally {
            @unlink($bin);
        }
    }
}
