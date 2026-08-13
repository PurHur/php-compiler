<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringSimilarText;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #30810: JIT/AOT similar_text routes through JitSimilarTextKernel (not NestedJIT helper).
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
        $charFn = $ctx->module->getNamedFunction('__phpc_similar_char');
        $strFn = $ctx->module->getNamedFunction('__phpc_similar_str');
        $this->assertNotNull($charFn);
        $this->assertNotNull($strFn);
        $this->assertGreaterThan(0, $charFn->countBasicBlocks());
        $this->assertGreaterThan(0, $strFn->countBasicBlocks());
    }

    public function testStringSimilarTextRoutesThroughJitSimilarTextKernel(): void
    {
        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringSimilarText.php');
        $this->assertStringContainsString('JitSimilarTextKernel', $runtimeSource);
        $this->assertStringNotContainsString('SimilarTextJitHelper::', $runtimeSource);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtimeSource);
    }
}
