<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringUtf8Runtime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #158 / #27051: AOT standalone must define __compiler_utf8_strlen on __string__*.
 *
 * @group aot-lint
 */
final class StringUtf8StrlenStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesUtf8StrlenFromSuperglobalsC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
        $this->assertFileExists(__DIR__.'/../../../lib/JIT/Builtin/StringUtf8StrlenJit.php');
        $this->assertFileExists(__DIR__.'/../../../lib/JIT/Builtin/StringUtf8ValidJit.php');
        $this->assertFileExists(__DIR__.'/../../../ext/standard/Utf8JitHelper.php');
    }

    public function testImplementDefinesUtf8StrlenForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringUtf8Runtime::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__compiler_utf8_strlen');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }

    public function testStringUtf8RuntimeDelegatesToLlvmAbiBodies(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringUtf8Runtime.php');
        $this->assertStringContainsString('StringUtf8StrlenJit::implement', $source);
        $this->assertStringContainsString('StringUtf8ValidJit::implement', $source);
        $this->assertStringContainsString('#27051', $source);
    }
}
