<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5315: AOT standalone must define GC helpers without phpc_gc.c.
 *
 * @group aot-lint
 */
final class GcCollectCyclesRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGcRuntimeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        GcCollectCyclesRuntime::ensureStandaloneBodies($ctx);

        foreach (
            [
                'phpc_gc_register',
                'phpc_gc_unregister',
                'phpc_destruct_set_allow_delref',
                'phpc_destruct_delref_allowed',
                'phpc_destruct_try_invoke',
                'phpc_gc_run_shutdown_destructors',
                'phpc_gc_enable',
                'phpc_gc_disable',
                'phpc_gc_is_enabled',
                '__compiler_gc_collect_cycles',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }

    public function testPhpcGcCRemovedFromLinkerAndRuntime(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_gc.c');
        $linker = file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_gc.c', $linker);
    }
}
