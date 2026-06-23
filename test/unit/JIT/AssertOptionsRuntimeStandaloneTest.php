<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\AssertOptionsRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9513 / #9894: AOT standalone assert_options must not use LLVM globals or __phpc_assert_* ABI.
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

        $this->assertNull($ctx->module->getNamedFunction('__phpc_assert_enabled'));
        $this->assertNull($ctx->module->getNamedFunction('__phpc_assert_ini_get_active'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_assert_active'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_assert_callback'));
    }
}
