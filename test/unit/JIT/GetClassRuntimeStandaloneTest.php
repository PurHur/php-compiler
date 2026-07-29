<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #10222: get_class() class-id lookup routes through GetClassJitHelper PHP.
 *
 * @group aot-lint
 */
final class GetClassRuntimeStandaloneTest extends TestCase
{
    public function testGetClassRuntimeBridgeContract(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/GetClassRuntime.php');
        $this->assertStringContainsString('__phpc_class_name_from_id', $runtime);
        $this->assertStringContainsString('GetClassJitHelper', $runtime);
        $this->assertStringContainsString('helperSourceForMap', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledFromSource', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        // Mid-emit ensureLinked must restore the outer insert block (#24163).
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $runtime);
        $this->assertStringContainsString('getInsertBlock', $runtime);
        $helper = (string) file_get_contents(__DIR__.'/../../../ext/standard/GetClassJitHelper.php');
        $this->assertStringContainsString('classNameFromClassId', $helper);
    }
}
