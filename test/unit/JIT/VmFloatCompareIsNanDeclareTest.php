<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Runtime;
use PHPCompiler\VM\VmFloatCompare;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Issue #21105: selfhost/minimal modules skip ext/standard Module init;
 * float-compare must declare libc isnan on demand.
 *
 * @group aot-lint
 */
final class VmFloatCompareIsNanDeclareTest extends TestCase
{
    public function testLookupOrDeclareIsNanIsIdempotent(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);

        $fn = VmFloatCompare::lookupOrDeclareIsNan($ctx);
        $this->assertSame($fn, $ctx->lookupFunction('isnan'));
        $this->assertSame($fn, VmFloatCompare::lookupOrDeclareIsNan($ctx));
    }

    public function testLookupOrDeclareIsNanRebindsWhenMissingFromScope(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        VmFloatCompare::lookupOrDeclareIsNan($ctx);

        $scopeProp = new ReflectionProperty(Context::class, 'functionScope');
        $scopeProp->setAccessible(true);
        $scope = $scopeProp->getValue($ctx);
        $this->assertIsArray($scope);
        unset($scope['isnan']);
        $scopeProp->setValue($ctx, $scope);

        try {
            $ctx->lookupFunction('isnan');
            $this->fail('expected lookupFunction to miss isnan after scope clear');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('isnan', $e->getMessage());
        }

        $fn = VmFloatCompare::lookupOrDeclareIsNan($ctx);
        $this->assertSame($fn, $ctx->lookupFunction('isnan'));
    }
}
