<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GetClassJitHelper;
use PHPUnit\Framework\TestCase;

/** ReflectionBuiltinHelper get_class LLVM routes through GetClassRuntime inline walk (#10222 / #26854). */
final class ReflectionBuiltinHelperRuntimeShrinkTest extends TestCase
{
    public function testReflectionBuiltinHelperUsesGetClassRuntimeInlineWalk(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ReflectionBuiltinHelper.php');
        $this->assertStringContainsString('GetClassRuntime::emitClassNameFromId', $source);
        $this->assertStringContainsString('GetClassRuntime::emitDebugTypeClassNameFromId', $source);
        $this->assertStringNotContainsString('__phpc_class_name_from_id', $source);
    }

    public function testGetClassRuntimeUsesMainModuleSelectWalk(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetClassRuntime.php');
        $this->assertStringContainsString('GetClassJitHelper', $source);
        $this->assertStringContainsString('helperSourceForMap', $source);
        $this->assertStringContainsString('emitSelectWalk', $source);
        $this->assertStringContainsString('constantStringFromString', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiledFromSource', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
    }

    public function testGetClassJitHelperLookup(): void
    {
        GetClassJitHelper::resetForTest();
        GetClassJitHelper::seedNamesById([3 => 'Foo\\Bar']);
        $this->assertSame('Foo\\Bar', GetClassJitHelper::classNameFromClassId(3));
        $this->assertSame('', GetClassJitHelper::classNameFromClassId(99));
    }
}
