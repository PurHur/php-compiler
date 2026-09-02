<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Standalone AOT GC sweep must clear own slots before mm_free (#36245).
 *
 * @group aot-lint
 */
final class GcCollectCyclesClearOwnSlotsTest extends TestCase
{
    public function testStandaloneFreeObjectClearsOwnSlotsFirst(): void
    {
        $kernel = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitGcCollectCyclesStandaloneKernel.php');
        $this->assertStringContainsString('phpc_gc_clear_object_own_slots', $kernel);
        $this->assertStringContainsString('implementClearObjectOwnSlots', $kernel);

        $nativeFree = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/GcCollectCyclesNativeFreeJitHelper.php');
        $this->assertStringContainsString('clearOwnSlots', $nativeFree);
    }

    public function testEnsureLinkedDefinesClearObjectOwnSlots(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        GcCollectCyclesRuntime::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('phpc_gc_clear_object_own_slots');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());

        $free = $ctx->lookupFunction('phpc_gc_free_object');
        $this->assertNotNull($free);
        $this->assertGreaterThan(0, $free->countBasicBlocks());
    }
}
