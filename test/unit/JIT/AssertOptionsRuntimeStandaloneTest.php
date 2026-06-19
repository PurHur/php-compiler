<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\AssertIniRuntime;
use PHPCompiler\JIT\Builtin\AssertOptionsRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9513: AOT standalone assert_options must use AssertOptionsJitHelper PHP, not LLVM globals.
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

        foreach (AssertIniRuntime::ABI_FUNCTIONS as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }

        $fn = $ctx->lookupFunction('__compiler_assert_options');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());

        $this->assertNull($ctx->module->getNamedGlobal('phpc_assert_active'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_assert_callback'));
    }
}
