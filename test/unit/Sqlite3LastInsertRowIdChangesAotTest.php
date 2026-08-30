<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SQLite3 lastInsertRowID/changes NestedJIT (#35931 leftover of #35914).
 *
 * @see php-src ext/sqlite3/sqlite3.c zim_SQLite3_lastInsertRowID / zim_SQLite3_changes
 *
 * @group llvm
 * @group aot
 */
final class Sqlite3LastInsertRowIdChangesAotTest extends TestCase
{
    public function testLastInsertRowIdAndChangesAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $src = __DIR__.'/../repro/sqlite3_lastinsertrowid_changes_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testProxyRegisteredForLastInsertRowIdAndChanges(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'lastInsertRowID'", $src);
        $this->assertStringContainsString("'changes'", $src);
        $dispatch = (string) file_get_contents($root.'/lib/JIT/Call/Sqlite3Method.php');
        $this->assertStringContainsString("'lastinsertrowid'", $dispatch);
        $this->assertStringContainsString("'changes'", $dispatch);
        $jit = (string) file_get_contents($root.'/ext/sqlite3/JitSqlite3.php');
        $this->assertStringContainsString('function lastInsertRowID(', $jit);
        $this->assertStringContainsString('function changes(', $jit);
        $this->assertStringContainsString('#35931', $jit);
    }

    private function runVm(string $src): string
    {
        return $this->runEnv(['PHP_COMPILER_PROFILE=8.4'], 'bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/sq3_lir_'.getmypid().'_'.md5($src);
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
