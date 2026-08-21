<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: realpath() via libc realpath(3) — NestedJIT helper returned empty (#33432).
 *
 * @see php-src ext/standard/basic_functions.c php_realpath
 *
 * @group llvm
 * @group aot
 */
final class RealpathAot33432Test extends TestCase
{
    public function testVmMatchesOk(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/realpath_aot_empty_33432.php');
        $this->assertNotFalse($code);
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'realpath_aot_empty_33432.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertSame("ok\n", $out);
    }

    public function testAotMatchesOk(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $tmpSrc = sys_get_temp_dir().'/phpc_issue_33432_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33432_'.getmypid().'.bin';
        copy($root.'/test/repro/realpath_aot_empty_33432.php', $tmpSrc);
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($tmpSrc).' 2>&1';
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("ok\n", implode("\n", $runOut)."\n");
        } finally {
            chdir($cwd);
            @unlink($bin);
            @unlink($tmpSrc);
        }
    }

    public function testBridgeUsesLibcNotNestedJitHelper(): void
    {
        $root = dirname(__DIR__, 2);
        $bridge = (string) file_get_contents($root.'/lib/JIT/Builtin/StringRealpath.php');
        $this->assertStringContainsString('RealpathLibcRuntime::emit', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink', $bridge);
        $this->assertStringNotContainsString('HELPER_PATH', $bridge);
        $this->assertStringNotContainsString('resolveArgv', $bridge);
        $libc = (string) file_get_contents($root.'/lib/JIT/Builtin/RealpathLibcRuntime.php');
        $this->assertStringContainsString("lookupFunction('realpath')", $libc);
        $this->assertStringContainsString('#33432', $libc);
        $this->assertFileDoesNotExist($root.'/runtime/realpath_libc.c');
    }
}
