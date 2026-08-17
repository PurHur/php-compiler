<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\LlvmToolchain;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Static property write inside closure persists on read-back (#31965).
 */
final class StaticPropertyClosureWriteRead31965Test extends TestCase
{
    /**
     * @covers issue #31965
     */
    public function testVmStaticPropertyClosureWriteRead(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_31965_static_property_closure.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_31965_static_property_closure.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("12\n", $out);
    }

    /**
     * Instability across runs was the signature of uninitialised-memory reads (#31965).
     *
     * @covers issue #31965
     *
     * @group llvm
     * @group aot
     */
    public function testAotStaticPropertyClosureWriteReadStableAcrossRuns(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_31965_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_31965_'.getmypid().'.bin';
        $code = file_get_contents($root.'/test/repro/issue_31965_static_property_closure.php');
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
                $this->assertSame(['12'], $runOut, 'run '.$i);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
