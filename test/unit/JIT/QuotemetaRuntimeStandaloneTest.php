<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringQuotemeta;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #30858: JIT/AOT quotemeta routes through QuotemetaJitHelper + VmQuotemeta bundle.
 *
 * @group aot-lint
 */
final class QuotemetaRuntimeStandaloneTest extends TestCase
{
    public function testImplementDefinesQuotemetaAbiForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringQuotemeta::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__string__quotemeta');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }

    public function testStringQuotemetaRoutesThroughVmQuotemetaBundle(): void
    {
        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringQuotemeta.php');
        $this->assertStringContainsString('VmQuotemeta.php', $runtimeSource);
        $this->assertStringContainsString('ensureCompiledBundle', $runtimeSource);
        $this->assertStringContainsString('QuotemetaJitHelper', $runtimeSource);
    }
}
