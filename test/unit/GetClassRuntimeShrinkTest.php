<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GetClassJitHelper;
use PHPCompiler\JIT\Builtin\GetClassRuntime;
use PHPUnit\Framework\TestCase;

/** GetClassRuntime inline select-walk for thin AOT get_class (#24976 / #26854). */
final class GetClassRuntimeShrinkTest extends TestCase
{
    public function testGetClassRuntimeEmitsInlineSelectWalk(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetClassRuntime.php');
        $this->assertStringContainsString('emitClassNameFromId', $source);
        $this->assertStringContainsString('emitSelectWalk', $source);
        $this->assertStringContainsString('seedThrowableClassNames', $source);
        $this->assertStringContainsString('RuntimeException', $source); // #27625 cross-function catch
        $this->assertStringContainsString('helperSourceForMap', $source);
        $this->assertStringContainsString('constantStringFromString', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiledFromSource', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('__phpc_class_name_from_id', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testHelperSourceForMapEmbedsClassTable(): void
    {
        $php = GetClassRuntime::helperSourceForMap([3 => 'Foo\\Bar', 7 => 'class@anonymous']);
        $this->assertStringContainsString('switch ($classId)', $php);
        $this->assertStringContainsString('case 3: return ', $php);
        $this->assertStringContainsString('Foo\\\\Bar', $php);
        $this->assertStringContainsString('case 7: return ', $php);
        $this->assertStringContainsString('class@anonymous', $php);
        $this->assertStringNotContainsString('private static array $namesById', $php);
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
