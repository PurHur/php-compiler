<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\WeakRefRegistryRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issues #5684 / #5303: AOT standalone must define weakref helpers without phpc_weakref.c.
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

        $verifyMessage = '';
        $ctx->module->verify($ctx->module::VERIFY_ACTION_RETURN, $verifyMessage);
        $this->assertStringNotContainsString('wr_clear_bridge_entry', $verifyMessage);
        $this->assertStringNotContainsString('wr_clear_refs_inc', $verifyMessage);
        $this->assertStringNotContainsString('ptrtoint i8* %0 to i64', strtolower($verifyMessage));
        $this->assertStringNotContainsString('parentless instruction', strtolower($verifyMessage));
        $this->assertStringNotContainsString('weakrefregistryjithelper__reftargetptr(i32', \strtolower($verifyMessage));
    }

    public function testPhpcWeakrefCDeletedAndGcNotifyInPhpRuntime(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_weakref.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_gc.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_weakref.c', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/WeakRefRegistryRuntime.php');
        $this->assertStringContainsString('WeakRefRegistryJitHelper', $runtime);
        $this->assertStringContainsString('Replaces lib/AOT/runtime/phpc_weakref.c', $runtime);
    }
}
