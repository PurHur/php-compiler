<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT gc_collect_cycles() cycle sweep + registry unregister invariants (#36245).
 *
 * @group aot-lint
 */
final class GcCollectCyclesAotCycleTest extends TestCase
{
    public function testUnregisterSwapCopiesMarkedAndInbound(): void
    {
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('G_MARKED', $runtime);
        $this->assertStringContainsString('G_INBOUND', $runtime);
        $this->assertStringContainsString('$lastMarked', $runtime);
        $this->assertStringContainsString('$lastInbound', $runtime);
    }

    public function testNativeScanSweepUsesPointerFreeList(): void
    {
        $scan = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/GcCollectCyclesNativeScanJitHelper.php');
        $free = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/GcCollectCyclesNativeFreeJitHelper.php');
        $kernel = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitGcCollectCyclesStandaloneKernel.php');
        $this->assertStringContainsString('$toFree', $scan);
        $this->assertStringContainsString('foreach ($toFree as $objPtr)', $scan);
        $this->assertStringContainsString('freeRegistryObject', $free);
        $this->assertStringContainsString('$slotEmpty', $kernel);
        $this->assertStringContainsString('slot_read_nonempty', $kernel);
        $this->assertStringContainsString('slot_read_raw_obj', $kernel);
        $this->assertStringContainsString("objMap['constructed']", $kernel);
    }

    public function testUnsetDelrefsBeforeWriteNull(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('valueDelref alone leaves extra GC roots (#36245)', $jit);
        $this->assertStringContainsString('refcount->delref($obj)', $jit);
    }

    public function testFunctionReturnDelrefsTypedObjectLocals(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('releaseJitNamedLocalAtReturn', $jit);
        $this->assertStringContainsString('releaseJitCanonicalNamedLocalAtReturn', $jit);
        $this->assertStringContainsString('jitFunctionAssignTargets', $jit);
        $this->assertStringContainsString('$var->type & Variable::IS_REFCOUNTED', $jit);
        $this->assertStringContainsString('skipAddrefForNewRvalue', $jit);
    }

    public function testUserScriptStandaloneRegistryResetWiredAtMain(): void
    {
        $ctx = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertStringContainsString(
            'emitUserScriptStandaloneRegistryReset',
            $ctx
        );
        $this->assertStringContainsString('isUserScriptAot()', $ctx);
        $this->assertStringContainsString('OpCode::TYPE_RETURN_VOID', $ctx);
        $this->assertStringContainsString('scope_exit', $ctx);
        $this->assertStringContainsString('objectMirrorSharesNamedCvAlloca', $ctx);
    }
}
