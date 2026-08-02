<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #10222 / #26854: get_class() class-id lookup via inline GetClassRuntime walk.
 *
 * @group aot-lint
 */
final class GetClassRuntimeStandaloneTest extends TestCase
{
    public function testGetClassRuntimeInlineContract(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/GetClassRuntime.php');
        $this->assertStringContainsString('emitClassNameFromId', $runtime);
        $this->assertStringContainsString('GetClassJitHelper', $runtime);
        $this->assertStringContainsString('helperSourceForMap', $runtime);
        $this->assertStringContainsString('emitSelectWalk', $runtime);
        $this->assertStringContainsString('constantStringFromString', $runtime);
        $this->assertStringContainsString('seedThrowableClassNames', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiledFromSource', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('__phpc_class_name_from_id', $runtime);
        $helper = (string) file_get_contents(__DIR__.'/../../../ext/standard/GetClassJitHelper.php');
        $this->assertStringContainsString('classNameFromClassId', $helper);
    }
}
