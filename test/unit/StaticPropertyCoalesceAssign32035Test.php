<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\LlvmToolchain;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Static property ??= stores and readback matches Zend (#32035).
 */
final class StaticPropertyCoalesceAssign32035Test extends TestCase
{
    /**
     * @covers issue #32035
     */
    public function testVmStaticPropertyCoalesceAssign(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/maintainer_gap_static_coalesce_assign.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_static_coalesce_assign.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "int(7)\nint(7)\nint(7)\nint(7)\nint(3)\nint(3)\n",
            $out
        );
    }

    /**
     * @covers issue #32035
     *
     * @group llvm
     * @group aot
     */
    public function testAotStaticPropertyCoalesceAssignStableAcrossRuns(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_32035_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_32035_'.getmypid().'.bin';
        $code = file_get_contents($root.'/test/repro/maintainer_gap_static_coalesce_assign.php');
        $this->assertNotFalse($code);
        file_put_contents($src, $code);

        $compile = escapeshellarg(PHP_BINARY).' '
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
                    ['int(7)', 'int(7)', 'int(7)', 'int(7)', 'int(3)', 'int(3)'],
                    $runOut,
                    'run '.$i
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
