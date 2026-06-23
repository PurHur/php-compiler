<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\FunctionStaticRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #10173: JIT function-static init flags use FunctionStaticRuntime table ABI, not per-slot LLVM globals.
 *
 * @group aot-lint
 */
final class FunctionStaticRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesFunctionStaticBridgesForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        FunctionStaticRuntime::ensureLinked($ctx);

        foreach (['phpc_fn_static_is_initialized', 'phpc_fn_static_mark_initialized'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }

        $this->assertNotNull($ctx->module->getNamedGlobal(FunctionStaticRuntime::INIT_TABLE_GLOBAL));
    }
}
