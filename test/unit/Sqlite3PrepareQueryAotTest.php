<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SQLite3::prepare/query NestedJIT (#36010 leftover of #36001).
 *
 * @see php-src ext/sqlite3/sqlite3.c zim_sqlite3_prepare / zim_sqlite3_query
 *
 * @group llvm
 * @group aot
 */
final class Sqlite3PrepareQueryAotTest extends TestCase
{
    public function testPrepareQueryAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $src = __DIR__.'/../repro/sqlite3_prepare_query_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testQueryPrepareComplianceReproMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $src = __DIR__.'/../repro/sqlite3_query_prepare.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testStmtBindParamComplianceReproMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $src = __DIR__.'/../repro/sqlite3_stmt_bindparam_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testProxiesRegistered(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'open', 'prepare', 'query'", $src);
        $this->assertStringContainsString("'bindValue', 'bindParam', 'execute', 'readOnly'", $src);
        $this->assertStringContainsString("'fetchArray', 'columnType'", $src);
        $this->assertStringContainsString('sqlite3stmt::', $src);
        $this->assertStringContainsString('sqlite3result::', $src);
        $jit = (string) file_get_contents($root.'/ext/sqlite3/JitSqlite3.php');
        $this->assertStringContainsString('function prepare(', $jit);
        $this->assertStringContainsString('function query(', $jit);
        $this->assertStringContainsString('#36010', $jit);
        $this->assertFileDoesNotExist($root.'/runtime/sqlite3_prepare.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/sqlite3_query.c');
    }

    private function runVm(string $src): string
    {
        return $this->runEnv(['PHP_COMPILER_PROFILE=8.4'], 'bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/sq3_pq_'.getmypid().'_'.md5($src);
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
