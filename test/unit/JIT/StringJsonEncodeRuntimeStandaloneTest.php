<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringJsonEncode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9267: json_encode LLVM helpers route through JsonEncodeJitHelper PHP.
 *
 * @group aot-lint
 */
final class StringJsonEncodeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRoutesJsonEncodeThroughPhpHelper(): void
    {
        $runtime = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringJsonEncode.php');
        $this->assertStringContainsString('JsonEncodeJitHelper', $runtime);
        $this->assertStringContainsString('StringJsonEncodeJit', $runtime);
        $this->assertLessThan(170, \substr_count($runtime, "\n"), 'StringJsonEncode must be a thin bridge (#9267)');

        $jitMonolith = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringJsonEncodeJit.php');
        $this->assertGreaterThan(500, \substr_count($jitMonolith, "\n"), 'StringJsonEncodeJit retains standalone LLVM');
    }

    public function testEnsureLinkedDefinesJsonEncodeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringJsonEncode::ensureLinked($ctx);

        foreach (['__compiler_json_encode_value', '__compiler_json_encode_array'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
        $this->assertNull($ctx->module->getNamedFunction('__compiler_json_encode_hashtable'));
    }
}
