<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** hash_algos JIT routes through HashAlgosJitHelper PHP not inline LLVM (#14909). */
final class StringHashAlgosRuntimeShrinkTest extends TestCase
{
    public function testStringHashAlgosUsesJitHelperBridgeNotInlineLlvm(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashAlgos.php');
        $this->assertStringContainsString('HashAlgosJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringNotContainsString('implementHashAlgos', $runtime);
        $this->assertStringNotContainsString('HashAlgosRegistry::ALL_ALGOS', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/hash/HashAlgosJitHelper.php');
        $this->assertStringContainsString('VmHash::algos', $helper);
    }
}
