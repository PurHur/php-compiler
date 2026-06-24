<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9585: __phpc_stat routes through StatArrayRuntime PHP + standalone LLVM quarantine.
 *
 * @group aot-lint
 */
final class StatArrayStandaloneTest extends TestCase
{
    public function testRuntimeShrinkMovesStatArrayOutOfStringFsDirJit(): void
    {
        $fsDir = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringFsDirJit.php');
        $this->assertStringContainsString('StatArrayRuntime::ensureLinked', $fsDir);
        $this->assertStringNotContainsString('emitStat', $fsDir);
        $this->assertStringNotContainsString("lookupFunction('lstat')", $fsDir);

        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StatArrayRuntime.php');
        $this->assertStringContainsString('StatArrayJitHelper', $runtime);
        $this->assertStringContainsString('StatArrayLlvm::implement', $runtime);
        $this->assertStringContainsString('__phpc_stat', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../../ext/standard/StatArrayJitHelper.php');
        $this->assertStringContainsString('VmStatCache::stat', $helper);
    }
}
