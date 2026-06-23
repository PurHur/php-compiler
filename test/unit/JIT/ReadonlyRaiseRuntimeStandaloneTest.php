<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ReadonlyRaise;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9522: AOT standalone readonly pending must use ReadonlyRaiseJitHelper PHP.
 *
 * @group aot-lint
 */
final class ReadonlyRaiseRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesReadonlyPendingForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ReadonlyRaise::ensureLinked($ctx);

        foreach (
            [
                '__compiler_jit_raise_logic_exception',
                'phpc_jit_clear_pending_exception',
                'phpc_jit_has_pending_exception',
                'phpc_jit_copy_pending_exception',
                'phpc_jit_abort_if_pending_logic_exception',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }

        $this->assertNull($ctx->module->getNamedGlobal('phpc_jit_pending_flag'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_jit_pending_msg'));
    }
}
