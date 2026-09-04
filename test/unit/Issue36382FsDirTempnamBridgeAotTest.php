<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — tempnam AOT via FsDirRuntime bridge stays module-verify clean.
 *
 * @group aot
 */
final class Issue36382FsDirTempnamBridgeAotTest extends TestCase
{
    public function testTempnamBridgeAotExecutesUnderLlvmAssert(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_fsdir_tempnam_bridge.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'fsdir36382_');
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
        $this->assertStringNotContainsString('Referring to a basic block in another function', $joined);
        $this->assertStringNotContainsString('fdr_tempnam_', $joined);
        $this->assertFileExists($out);
        exec(escapeshellarg($out).' 2>&1', $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $this->assertSame('ok', trim(implode("\n", $runLines)));
    }

    public function testFsDirRuntimeScopesBridgeLowering(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/FsDirRuntime.php');
        $this->assertStringContainsString('scopeLoweringToFunction', $source);
        $this->assertStringContainsString('fdr_tempnam_notice_do', $source);
        $this->assertStringNotContainsString(
            "BasicBlockHelper::append(\$context, 'fdr_tempnam_",
            $source
        );
    }
}
