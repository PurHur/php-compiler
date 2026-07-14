<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** hash_hmac_algos JIT routes through HashAlgosJitHelper PHP; standalone defer uses inline registry LLVM (#18908, #7189). */
final class StringHashHmacAlgosRuntimeShrinkTest extends TestCase
{
    public function testStringHashHmacAlgosEmbedPathUsesJitHelperBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashHmacAlgos.php');
        $this->assertStringContainsString('HashAlgosJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('UserScriptAotDeferNestedJit::shouldDefer', $runtime);
        $this->assertStringContainsString('implementInlineRegistry', $runtime);
        $this->assertStringNotContainsString('implementHashHmacAlgos', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/hash/HashAlgosJitHelper.php');
        $this->assertStringContainsString('VmHash::hmacAlgos', $helper);
        $this->assertStringContainsString('hmacAlgosArgv', $helper);
    }

    public function testStringHashHmacAlgosDeferUsesRegistryConstantsNotNestedJit(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashHmacAlgos.php');
        $this->assertStringContainsString('HashAlgosRegistry::HMAC_ALGOS', $runtime);
        $this->assertStringContainsString('__hashtable__setStringAt', $runtime);
    }
}
