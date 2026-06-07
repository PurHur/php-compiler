<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\WeakRefRegistryRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5684: AOT standalone must define weakref helpers without phpc_weakref.c.
 *
 * @group aot-lint
 */
final class WeakRefRegistryRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesWeakRefRegistryForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        WeakRefRegistryRuntime::ensureLinked($ctx);

        foreach (
            [
                'phpc_weakref_reset',
                'phpc_weakref_register_ref',
                'phpc_weakref_register_map',
                'phpc_weakref_unregister_map',
                'phpc_weakref_clear_object',
                'phpc_weakref_clear_object_typed',
                'phpc_weakref_format_object_key',
                'phpc_gc_notify_object_freed',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }

    public function testPhpcGcNoLongerReferencesWeakrefClearSymbols(): void
    {
        $gc = file_get_contents(__DIR__.'/../../../lib/AOT/runtime/phpc_gc.c');
        $this->assertIsString($gc);
        $this->assertStringNotContainsString('phpc_weakref_clear_object', $gc);
        $this->assertStringContainsString('phpc_gc_notify_object_freed', $gc);
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_weakref.c');
    }
}
