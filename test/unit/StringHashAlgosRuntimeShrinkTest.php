<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** hash_algos JIT routes through HashAlgosJitHelper PHP; standalone defer uses inline registry LLVM (#14909, #3357). */
final class StringHashAlgosRuntimeShrinkTest extends TestCase
{
    public function testStringHashAlgosEmbedPathUsesJitHelperBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashAlgos.php');
        $this->assertStringContainsString('HashAlgosJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('UserScriptAotDeferNestedJit::shouldDefer', $runtime);
        $this->assertStringContainsString('implementInlineRegistry', $runtime);
        $this->assertStringNotContainsString('implementHashAlgos', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/hash/HashAlgosJitHelper.php');
        $this->assertStringContainsString('VmHash::algos', $helper);
    }

    public function testStringHashAlgosDeferUsesRegistryConstantsNotNestedJit(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashAlgos.php');
        $this->assertStringContainsString('HashAlgosRegistry::ALL_ALGOS', $runtime);
        $this->assertStringContainsString('__hashtable__setStringAt', $runtime);
    }
}
