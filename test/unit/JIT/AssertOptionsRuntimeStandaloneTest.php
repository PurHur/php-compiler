<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\AssertOptionsRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9513 / #9894 / #21528: AOT standalone assert_options uses AssertOptionsJitHelper, not false stub.
 *
 * @group aot-lint
 */
final class AssertOptionsRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesAssertOptionsForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        AssertOptionsRuntime::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__compiler_assert_options');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());

        $entryNames = [];
        foreach ($fn->getBasicBlocks() as $block) {
            $entryNames[] = $block->getName();
        }
        $this->assertNotContains('aopt_standalone_stub', $entryNames);
        $this->assertContains('aopt_entry', $entryNames);

        $this->assertNull($ctx->module->getNamedFunction('__phpc_assert_enabled'));
        $this->assertNull($ctx->module->getNamedFunction('__phpc_assert_ini_get_active'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_assert_active'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_assert_callback'));

        // Helper symbols from AssertOptionsJitHelper must be present after link.
        $this->assertArrayHasKey(
            strtolower(AssertOptionsRuntime::IS_ENABLED),
            $ctx->functions
        );
    }
}
