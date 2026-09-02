<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SQLite3 lastErrorCode/lastErrorMsg NestedJIT (#35966 leftover of #35931).
 *
 * @see php-src ext/sqlite3/sqlite3.c zim_SQLite3_lastErrorCode / zim_SQLite3_lastErrorMsg
 *
 * @group llvm
 * @group aot
 */
final class Sqlite3LastErrorAotTest extends TestCase
{
    public function testLastErrorAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $src = __DIR__.'/../repro/sqlite3_lasterror_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testProxyRegisteredForLastError(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/sqlite3/Module.php');
        $this->assertStringContainsString("'lastErrorCode'", $src);
        $this->assertStringContainsString("'lastErrorMsg'", $src);
        $dispatch = (string) file_get_contents($root.'/lib/JIT/Call/Sqlite3Method.php');
        $this->assertStringContainsString("'lasterrorcode'", $dispatch);
        $this->assertStringContainsString("'lasterrormsg'", $dispatch);
        $jit = (string) file_get_contents($root.'/ext/sqlite3/JitSqlite3.php');
        $this->assertStringContainsString('function lastErrorCode(', $jit);
        $this->assertStringContainsString('function lastErrorMsg(', $jit);
        $this->assertStringContainsString('#35966', $jit);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/sqlite3_lasterror.c');
        $this->assertFileDoesNotExist($root.'/runtime/sqlite3_lasterror.c');
    }

    private function runVm(string $src): string
    {
        return $this->runEnv(['PHP_COMPILER_PROFILE=8.4'], 'bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/sq3_err_'.getmypid().'_'.md5($src);
        $compile = 'env PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile.' 2>&1', $cout, $crc);
            $this->assertSame(0, $crc, implode("\n", $cout));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            @unlink($bin);
            chdir($cwd);
        }
    }

    /**
     * @param list<string> $env
     */
    private function runEnv(array $env, string $binRel, string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env '.implode(' ', $env).' '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/'.$binRel).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }
}
