<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AttributeRegistryJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * AttributeRegistry lookup: no thin stubs; host-decoded strcasecmp bridges (#10086, #20901).
 */
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

    public function testAttributeRegistryLookupRuntimeDropsThinStubs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AttributeRegistryLookupRuntime.php');
        $this->assertStringContainsString('StringCaseCompare::ensureStrcasecmpLinked', $source);
        $this->assertStringContainsString('decodeClassNames', $source);
        $this->assertStringContainsString('strcasecmp', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementThinStandaloneStubs', $source);
        $this->assertStringNotContainsString('implementDeferredSizeTUnaryStub', $source);
        $this->assertStringNotContainsString('implementDeferredCstrTernaryStub', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('implementDeferredUserScriptStubs', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        // No NestedJIT of AttributeRegistryJitHelper (thin-AOT unsafe JSON scanner).
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $source);
        $this->assertStringNotContainsString('AttributeRegistryJitHelper::classCount', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
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

    public function testSpineBundleIncludesAttributeRegistryPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AttributeRegistryJitHelper.php', $spine);
        $this->assertStringContainsString('AttributeRegistryArgsJitHelper.php', $spine);
        $this->assertStringContainsString('AttributeRegistryLookupRuntime.php', $spine);
    }
}
