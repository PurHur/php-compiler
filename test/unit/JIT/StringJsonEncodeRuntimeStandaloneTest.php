<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9267 / #13239: json_encode LLVM helpers route through JsonEncodeJitHelper PHP.
 *
 * @group aot-lint
 */
final class StringJsonEncodeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRoutesJsonEncodeThroughPhpHelper(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringJsonEncodeJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/JsonLastErrorGlobal.php');
        $runtime = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringJsonEncode.php');
        $this->assertStringContainsString('JsonEncodeJitHelper', $runtime);
        $this->assertStringNotContainsString('StringJsonEncodeJit', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertLessThan(170, \substr_count($runtime, "\n"), 'StringJsonEncode must be a thin bridge (#9267)');
        $helper = (string) \file_get_contents(__DIR__.'/../../../ext/standard/JsonEncodeJitHelper.php');
        $this->assertStringContainsString('VmJson::export', $helper);
    }
}
