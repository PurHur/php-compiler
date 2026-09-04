<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — string-key write of native string[] must AOT-verify (JitValueBox path).
 *
 * @group aot
 */
final class Issue36382HtStringKeyNativeArrAotTest extends TestCase
{
    public function testHtStringKeyNativeArrayAotVerifiesAndRuns(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_ht_string_key_native_arr.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'htsna36382_');
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
        $this->assertStringNotContainsString('Stored value type does not match', $joined);
        $this->assertStringNotContainsString('Module verification failed', $joined);
        $this->assertFileExists($out);
        exec(escapeshellarg($out).' 2>&1', $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $this->assertSame('x', trim(implode("\n", $runLines)));
    }
}
