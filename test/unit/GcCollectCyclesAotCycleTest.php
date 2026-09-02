<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT gc_collect_cycles() must not segfault on cyclic object graphs (#36245).
 *
 * @group aot-lint
 */
final class GcCollectCyclesAotCycleTest extends TestCase
{
    public function testStandaloneUserScriptRoutesCollectThroughPhpRegistry(): void
    {
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $collect = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/GcCollectCyclesCollectRuntime.php');
        $this->assertStringContainsString('ensurePhpRegistryUserScriptBodies', $runtime);
        $this->assertStringContainsString('GcCollectCyclesStandaloneJitHelper::collectCyclesStandalone', $collect);
        $this->assertStringContainsString('collectImplHelperFunction', $collect);
    }
}
