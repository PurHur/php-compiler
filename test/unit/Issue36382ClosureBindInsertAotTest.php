<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Closure::bindTo AOT keeps insert block after ClosureBindRuntime link.
 *
 * @group aot
 */
final class Issue36382ClosureBindInsertAotTest extends TestCase
{
    public function testBindToAotModuleVerifyClean(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_closure_bind_insert.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'cbind36382_');
        $this->assertNotFalse($out);
        @unlink($out);
        putenv('PHP_COMPILER_LLVM_ASSERT=1');
        $_ENV['PHP_COMPILER_LLVM_ASSERT'] = '1';
        putenv('PHP_COMPILER_CACHE=0');
        $_ENV['PHP_COMPILER_CACHE'] = '0';
        $cmd = sprintf(
            'php -d memory_limit=1024M %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        exec($cmd, $lines, $ec);
        putenv('PHP_COMPILER_LLVM_ASSERT');
        unset($_ENV['PHP_COMPILER_LLVM_ASSERT']);
        putenv('PHP_COMPILER_CACHE');
        unset($_ENV['PHP_COMPILER_CACHE']);
        $joined = implode("\n", $lines);
        $this->assertSame(0, $ec, $joined);
        $this->assertStringNotContainsString('not embedded in a basic block', $joined);
        $this->assertStringNotContainsString('Module verification failed', $joined);
        $this->assertFileExists($out);
        @unlink($out);
    }
}
