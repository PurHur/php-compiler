<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Object property foreach JIT routes through VmObjectPropertyForeach PHP SSOT (#10239). */
final class ObjectPropertyForeachHelperRuntimeShrinkTest extends TestCase
{
    public function testObjectPropertyForeachHelperIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ObjectPropertyForeachHelper.php');
        $this->assertStringContainsString('VmObjectPropertyForeach', $source);
        $this->assertStringNotContainsString('validForRuntimeClass', $source);
        $this->assertStringNotContainsString('fetchPropertyForClassAtIndex', $source);
        $this->assertStringNotContainsString('foreach_objprop_valid_class_', $source);
        $this->assertLessThanOrEqual(65, substr_count($source, "\n") + 1);
    }

    public function testVmObjectPropertyForeachOwnsPropertyIterationLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmObjectPropertyForeach.php');
        $this->assertStringContainsString('VmIteratorProtocol', $source);
        $this->assertStringContainsString('validForRuntimeClass', $source);
        $this->assertStringContainsString('fetchPropertyForClassAtIndex', $source);
        $this->assertStringContainsString('emitPropertyNameAtIndex', $source);
        $this->assertGreaterThan(250, substr_count($source, "\n") + 1);
    }
}
