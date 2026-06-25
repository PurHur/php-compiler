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
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $helper = (string) file_get_contents(__DIR__.'/../../../ext/standard/GetClassJitHelper.php');
        $this->assertStringContainsString('classNameFromClassId', $helper);
    }
}
