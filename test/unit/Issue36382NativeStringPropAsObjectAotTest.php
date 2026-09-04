<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Native object arg must not readObject a string prop load.
 *
 * @group aot
 */
final class Issue36382NativeStringPropAsObjectAotTest extends TestCase
{
    public function testStringPropAsObjectArgAotVerifiesAndRuns(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_native_string_prop_as_object.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'nspo36382_');
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
        $this->assertStringNotContainsString('Call parameter type does not match', $joined);
        $this->assertStringNotContainsString('Module verification failed', $joined);
        $this->assertFileExists($out);
        @unlink($out);
    }
}
