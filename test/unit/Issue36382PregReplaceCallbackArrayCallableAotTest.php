<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: preg_replace_callback([__CLASS__, 'method'], …) for Slim/Nyholm Uri (#36382).
 *
 * @see php-src ext/pcre/php_pcre.c PHP_FUNCTION(preg_replace_callback)
 *
 * @group llvm
 * @group aot
 */
final class Issue36382PregReplaceCallbackArrayCallableAotTest extends TestCase
{
    private const EXPECT = "a%20b%3Ac";

    public function testVmMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/issue_36382_preg_replace_callback_array_callable.php';
        $this->assertFileExists($src);
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile((string) file_get_contents($src), basename($src)));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, trim($out));
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_preg_replace_callback_array_callable.php';
        $bin = sys_get_temp_dir().'/phpc_36382_preg_arr_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' --no-cache -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut));
            // Heap corruption is intermittent here — second run must also match (#23842 class).
            $runOut2 = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut2, $runRc2);
            $this->assertSame(0, $runRc2, implode("\n", $runOut2));
            $this->assertSame(self::EXPECT, implode("\n", $runOut2));
        } finally {
            @unlink($bin);
        }
    }

    public function testAotExplicitClassNameArrayCallable(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = <<<'PHP'
        <?php
        class U {
            public static function up(array $m): string { return strtoupper($m[0]); }
        }
        echo preg_replace_callback('/[a-z]+/', ['U', 'up'], 'ab CD'), "\n";
        PHP;
        $root = dirname(__DIR__, 2);
        $path = sys_get_temp_dir().'/phpc_36382_preg_arr2_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_36382_preg_arr2_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $compile = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' --no-cache -o '.escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(['AB CD'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
