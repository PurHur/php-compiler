<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GetClassJitHelper;
use PHPUnit\Framework\TestCase;

/** ReflectionBuiltinHelper get_class LLVM routes through GetClassJitHelper PHP (#10222). */
final class ReflectionBuiltinHelperRuntimeShrinkTest extends TestCase
{
    public function testReflectionBuiltinHelperUsesGetClassRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ReflectionBuiltinHelper.php');
        $this->assertStringContainsString('GetClassRuntime::ensureLinked', $source);
        $this->assertStringContainsString('__phpc_class_name_from_id', $source);
        $this->assertStringNotContainsString('allClassNamesById()', $source);
        $this->assertStringNotContainsString('->select($isId', $source);
    }

    public function testGetClassRuntimeUsesGeneratedJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetClassRuntime.php');
        $this->assertStringContainsString('GetClassJitHelper', $source);
        $this->assertStringContainsString('helperSourceForMap', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
    }

    public function testGetClassJitHelperLookup(): void
    {
        GetClassJitHelper::resetForTest();
        GetClassJitHelper::seedNamesById([3 => 'Foo\\Bar']);
        $this->assertSame('Foo\\Bar', GetClassJitHelper::classNameFromClassId(3));
        $this->assertSame('', GetClassJitHelper::classNameFromClassId(99));
    }
}
