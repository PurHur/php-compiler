<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Runtime;
use PHPCompiler\VM\VmFloatCompare;
use PHPUnit\Framework\TestCase;

/**
 * Issue #21105: selfhost/minimal modules skip ext/standard Module init;
 * float-compare must declare libc isnan on demand.
 *
 * @group aot-lint
 */
final class VmFloatCompareIsNanDeclareTest extends TestCase
{
    public function testLookupOrDeclareIsNanWithoutModuleInit(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);

        try {
            $ctx->lookupFunction('isnan');
            $this->markTestSkipped('isnan already registered by Module init on this path');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('isnan', $e->getMessage());
        }

        $fn = VmFloatCompare::lookupOrDeclareIsNan($ctx);
        $this->assertSame($fn, $ctx->lookupFunction('isnan'));
        $this->assertSame($fn, VmFloatCompare::lookupOrDeclareIsNan($ctx));
    }
}
