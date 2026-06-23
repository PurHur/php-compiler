<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\AttributeNewInstanceJitHelper;
use PHPUnit\Framework\TestCase;

/** ReflectionAttribute::newInstance() JIT routes through AttributeNewInstanceJitHelper PHP (#10274). */
final class AttributeNewInstanceRuntimeShrinkTest extends TestCase
{
    public function testAttributeNewInstanceHelperDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/AttributeNewInstanceHelper.php');
    }

    public function testReflectionAttributeNewInstanceUsesRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/ReflectionAttributeNewInstance.php');
        $this->assertStringContainsString('AttributeNewInstanceRuntime', $source);
        $this->assertStringNotContainsString('AttributeNewInstanceHelper', $source);
    }

    public function testAttributeNewInstanceRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AttributeNewInstanceRuntime.php');
        $this->assertStringContainsString('AttributeNewInstanceJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('strcasecmpFn', $source);
    }

    public function testResolveClassIdInsensitive(): void
    {
        $packed = "route\0deprecated\0attribute";
        $ids = '12,34,56';
        $this->assertSame(12, AttributeNewInstanceJitHelper::resolveClassId('Route', $packed, $ids));
        $this->assertSame(34, AttributeNewInstanceJitHelper::resolveClassId('\\Deprecated', $packed, $ids));
        $this->assertSame(-1, AttributeNewInstanceJitHelper::resolveClassId('Missing', $packed, $ids));
    }
}
