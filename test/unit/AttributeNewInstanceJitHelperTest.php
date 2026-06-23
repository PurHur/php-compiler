<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\AttributeNewInstanceJitHelper;
use PHPUnit\Framework\TestCase;

final class AttributeNewInstanceJitHelperTest extends TestCase
{
    public function testResolveClassIdCaseInsensitive(): void
    {
        $packed = "allowdynamicproperties\0route";
        $ids = '7,9';
        $this->assertSame(7, AttributeNewInstanceJitHelper::resolveClassId('AllowDynamicProperties', $packed, $ids));
        $this->assertSame(9, AttributeNewInstanceJitHelper::resolveClassId('route', $packed, $ids));
    }

    public function testResolveClassIdEmptyTable(): void
    {
        $this->assertSame(-1, AttributeNewInstanceJitHelper::resolveClassId('A', '', ''));
    }
}
