<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GetClassJitHelper;
use PHPCompiler\JIT\Builtin\GetClassRuntime;
use PHPUnit\Framework\TestCase;

/** GetClassRuntime NestedJIT → JitVmHelperLink::ensureCompiledFromSource (#24976). */
final class GetClassRuntimeShrinkTest extends TestCase
{
    public function testGetClassRuntimeRoutesThroughEnsureCompiledFromSource(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetClassRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledFromSource', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('helperSourceForMap', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(170, \substr_count($source, "\n") + 1);
    }

    public function testJitVmHelperLinkExposesEnsureCompiledFromSource(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitVmHelperLink.php');
        $this->assertStringContainsString('function ensureCompiledFromSource(', $source);
        $this->assertStringContainsString('Intentionally skip HelperRuntimeCache', $source);
        $this->assertStringContainsString('runNestedHelperCompile', $source);
    }

    public function testHelperSourceForMapEmbedsClassTable(): void
    {
        $php = GetClassRuntime::helperSourceForMap([3 => 'Foo\\Bar', 7 => 'class@anonymous']);
        $this->assertStringContainsString('Foo\\\\Bar', $php);
        $this->assertStringContainsString('class@anonymous', $php);
        $this->assertStringContainsString('classNameFromClassId', $php);
        $this->assertStringContainsString('debugTypeClassNameFromClassId', $php);
    }

    public function testOnDiskGetClassJitHelperSeedStubRemains(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GetClassJitHelper.php');
        $this->assertStringContainsString('seedNamesById', $source);
        $this->assertStringContainsString('classNameFromClassId', $source);

        GetClassJitHelper::resetForTest();
        GetClassJitHelper::seedNamesById([3 => 'Foo\\Bar']);
        $this->assertSame('Foo\\Bar', GetClassJitHelper::classNameFromClassId(3));
        $this->assertSame('', GetClassJitHelper::classNameFromClassId(99));
    }
}
