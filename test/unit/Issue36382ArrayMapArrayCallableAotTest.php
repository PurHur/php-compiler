<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_map static + bound array callables (#36382 / #1154).
 *
 * @see php-src ext/standard/array.c php_array_map()
 *
 * @group llvm
 * @group aot
 */
final class Issue36382ArrayMapArrayCallableAotTest extends TestCase
{
    public function testStaticVmMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/issue_36382_array_map_static_callable.php';
        $this->assertFileExists($src);
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile((string) file_get_contents($src), basename($src)));
        $out = (string) ob_get_clean();
        $this->assertSame('2,4,6', trim($out));
    }

    public function testStaticAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_array_map_static_callable.php';
        $bin = sys_get_temp_dir().'/phpc_36382_amap_st_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' --no-cache -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(['2,4,6'], $runOut);
        } finally {
            @unlink($bin);
        }
    }

    public function testBoundVmMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/issue_36382_array_map_bound_callable.php';
        $this->assertFileExists($src);
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile((string) file_get_contents($src), basename($src)));
        $out = (string) ob_get_clean();
        $this->assertSame('P:1,2|P:3', trim($out));
    }

    public function testBoundAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_array_map_bound_callable.php';
        $bin = sys_get_temp_dir().'/phpc_36382_amap_bd_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' --no-cache -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(['P:1,2|P:3'], $runOut);
            // Heap corruption is intermittent — second run (#23842 class).
            $runOut2 = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut2, $runRc2);
            $this->assertSame(0, $runRc2, implode("\n", $runOut2));
            $this->assertSame(['P:1,2|P:3'], $runOut2);
        } finally {
            @unlink($bin);
        }
    }
}
