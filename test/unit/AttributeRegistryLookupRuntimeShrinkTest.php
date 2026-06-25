<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AttributeRegistryJitHelper;
use PHPUnit\Framework\TestCase;

/** AttributeRegistry lookup JIT routes through AttributeRegistryJitHelper PHP (#10086). */
final class AttributeRegistryLookupRuntimeShrinkTest extends TestCase
{
    public function testAttributeRegistryLoweringUsesLookupRuntimeNotLlvmChains(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AttributeRegistryLowering.php');
        $this->assertStringContainsString('AttributeRegistryLookupRuntime::implement', $source);
        $this->assertStringNotContainsString('implementClassCount(', $source);
        $this->assertStringNotContainsString('implementClassNameAt(', $source);
        $this->assertStringNotContainsString('implementMethodCount(', $source);
        $this->assertStringNotContainsString('implementMethodNameAt(', $source);
        $this->assertStringNotContainsString('implementClassArgsHashtable(', $source);
        $this->assertStringNotContainsString('emitBuildArgsHashtable(', $source);
        $lineCount = substr_count($source, "\n") + 1;
        $this->assertLessThan(150, $lineCount);
        $this->assertGreaterThan(250, 439 - $lineCount);
    }

    public function testAttributeRegistryLookupRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AttributeRegistryLookupRuntime.php');
        $this->assertStringContainsString('AttributeRegistryJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('emitCstrEqualsLiteral', $source);
    }

    public function testAttributeRegistryJitHelperLookupSemantics(): void
    {
        $classJson = '{"box":["AllowDynamicProperties"],"widget":["Deprecated"]}';
        $this->assertSame(1, AttributeRegistryJitHelper::classCount('box', $classJson));
        $this->assertSame(0, AttributeRegistryJitHelper::classCount('missing', $classJson));
        $this->assertSame('AllowDynamicProperties', AttributeRegistryJitHelper::classNameAt('BOX', 0, $classJson));
        $this->assertSame('', AttributeRegistryJitHelper::classNameAt('box', 3, $classJson));

        $methodJson = '{"box":{"ping":["Deprecated"],"pong":["Internal"]}}';
        $this->assertSame(1, AttributeRegistryJitHelper::methodCount('box', 'ping', $methodJson));
        $this->assertSame(0, AttributeRegistryJitHelper::methodCount('box', 'missing', $methodJson));
        $this->assertSame('Deprecated', AttributeRegistryJitHelper::methodNameAt('box', 'PING', 0, $methodJson));
        $this->assertSame('', AttributeRegistryJitHelper::methodNameAt('box', 'ping', 1, $methodJson));
    }
}
