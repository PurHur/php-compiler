<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringOffsetRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #21497: AOT standalone string offset normalize NestedJIT StringOffsetJitHelper (no load-type fork).
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
        $this->assertNotNull(
            $ctx->functions[\strtolower('PHPCompiler\\VM\\StringOffsetJitHelper::normalizeByteIndex')] ?? null,
            'standalone AOT must NestedJIT StringOffsetJitHelper (#21497)'
        );
        $entryNames = [];
        foreach ($fn->getBasicBlocks() as $block) {
            $entryNames[] = $block->getName();
        }
        $this->assertContains('string_offset_norm_bridge_entry', $entryNames);
        $this->assertNotContains('string_offset_norm_neg', $entryNames);
    }
}
