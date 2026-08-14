<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ReflectionProperty setAccessible/getValue + ReflectionMethod invoke (#30910).
 *
 * @group llvm
 * @group aot
 */
final class Issue30910ReflectionSetAccessibleAotTest extends TestCase
{
    public function testAotSetAccessibleGetValueAndInvoke(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_30910_reflection_setaccessible_aot.php';
        $bin = sys_get_temp_dir().'/phpc_30910_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame("1\n9\n6\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($bin);
        }
    }
}
