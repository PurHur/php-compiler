<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\is_resource_;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamFilterChain;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** is_resource() stream-filter resources via resolveHandle (#7075). */
final class IsResourceObjectTest extends TestCase
{
    public function testIsResourceRecognizesStreamFilterLegacyHandle(): void
    {
        $handle = VmFs::fopen('php://memory', 'w+');
        $this->assertNotFalse($handle);
        $filterId = VmStreamFilterChain::append($handle, 'string.rot13', VmStreamFilterChain::READ);
        $this->assertNotFalse($filterId);
        $filter = new Variable();
        VmStreamFilterChain::filterHandle($filter, (int) $filterId, null);
        $this->assertTrue(is_resource_::isResource($filter));
        VmFs::fclose($handle);
    }

    public function testIsResourceSourceUsesResolveHandleForNonStreamKinds(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/is_resource_.php');
        $this->assertStringContainsString('ResourceSupport::resolveHandle($v)', $source);
        $this->assertStringNotContainsString('VmStreamFilterChain::isValidFilter($v->toInt())', $source);
    }

    public function testVmVarFormatIncludesStreamFilterKind(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmVarFormat.php');
        $this->assertStringContainsString('KIND_STREAM_FILTER', $source);
        $this->assertStringContainsString('VmStreamFilterChain::getResourceType', $source);
    }
}
