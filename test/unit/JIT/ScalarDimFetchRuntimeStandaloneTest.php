<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ScalarDimFetchRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #10343 / #10526: AOT standalone scalar dim fetch uses inline LLVM bridge (no nested JIT).
 *
 * @group aot-lint
 */
final class ScalarDimFetchRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesEmitWarningBridgeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ScalarDimFetchRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__scalar_dim_fetch__emitWarning');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
        $this->assertNull(
            $ctx->functions[\strtolower('PHPCompiler\\VM\\ScalarDimFetchJitHelper::emitWarningForJitType')] ?? null,
            'standalone AOT must not nest-compile ScalarDimFetchJitHelper (#10526)'
        );
    }
}
