<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\MimeContentTypeRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9236: mime_content_type JIT bridge compiles MimeContentTypeJitHelper, not LLVM sniff.
 *
 * @group aot-lint
 */
final class MimeContentTypeRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesMimeContentTypeBridge(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        MimeContentTypeRuntime::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__compiler_mime_content_type');
        $this->assertNotNull($fn, '__compiler_mime_content_type must be linked for standalone AOT');
        $this->assertGreaterThan(0, $fn->countBasicBlocks(), '__compiler_mime_content_type must have LLVM body');

        $lc = \strtolower('PHPCompiler\\ext\\standard\\MimeContentTypeJitHelper::mimeContentType');
        $this->assertArrayHasKey($lc, $ctx->functions, 'MimeContentTypeJitHelper::mimeContentType must be compiled into module');
    }
}
