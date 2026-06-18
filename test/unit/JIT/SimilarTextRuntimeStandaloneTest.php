<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringSimilarText;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9731: JIT/AOT similar_text routes through SimilarTextJitHelper PHP.
 *
 * @group aot-lint
 */
final class SimilarTextRuntimeStandaloneTest extends TestCase
{
    public function testImplementDefinesPhpcSimilarTextForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringSimilarText::implement($ctx);

        $fn = $ctx->lookupFunction('phpc_similar_text');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
        $this->assertNull($ctx->module->getNamedFunction('__phpc_similar_char'));
        $this->assertNull($ctx->module->getNamedFunction('__phpc_similar_str'));
    }

    public function testStringSimilarTextJitRoutesThroughSimilarTextJitHelper(): void
    {
        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringSimilarText.php');
        $this->assertStringContainsString('SimilarTextJitHelper', $runtimeSource);
        $this->assertStringNotContainsString('emitSimilarText', $runtimeSource);

        $helperSource = (string) \file_get_contents(__DIR__.'/../../../ext/standard/SimilarTextJitHelper.php');
        $this->assertStringContainsString('VmString::similar_text', $helperSource);
    }
}
