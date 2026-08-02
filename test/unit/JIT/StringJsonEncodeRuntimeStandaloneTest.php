<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9267 / #13239 / #20816: json_encode LLVM helpers route through JsonEncodeJitHelper PHP.
 *
 * @group aot-lint
 */
final class StringJsonEncodeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRoutesJsonEncodeThroughPhpHelper(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringJsonEncodeJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/JsonLastErrorGlobal.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringJsonEncodeInventoryStubs.php');
        $runtime = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringJsonEncode.php');
        $this->assertStringContainsString('JsonEncodeNestedJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $runtime);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $runtime);
        $this->assertStringNotContainsString('StringJsonEncodeJit', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertLessThan(180, \substr_count($runtime, "\n"), 'StringJsonEncode must be a thin bridge (#20816)');
        $helper = (string) \file_get_contents(__DIR__.'/../../../ext/standard/JsonEncodeNestedJitHelper.php');
        $this->assertStringContainsString('encodeHashtable', $helper);
        $this->assertStringContainsString('exportKeyValuePairs', $helper);
        $this->assertStringNotContainsString('VmJson::export', $helper);
        $this->assertStringNotContainsString('->runtime->vm', $helper);
    }
}
