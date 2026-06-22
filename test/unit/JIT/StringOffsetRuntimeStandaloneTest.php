<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringOffsetRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #10245: AOT standalone string offset normalize uses inline LLVM bridge (no nested JIT).
 *
 * @group aot-lint
 */
final class StringOffsetRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesNormalizeBridgeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringOffsetRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__string_offset__normalize');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
        $this->assertNull(
            $ctx->functions[\strtolower('PHPCompiler\\VM\\StringOffsetJitHelper::normalizeByteIndex')] ?? null,
            'standalone AOT must not nest-compile StringOffsetJitHelper (#10245)'
        );
    }
}
