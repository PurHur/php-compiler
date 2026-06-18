<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\GcToggleRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9687: AOT standalone gc toggle must use GcToggleJitHelper PHP, not phpc_gc_enabled LLVM global.
 *
 * @group aot-lint
 */
final class GcToggleRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGcToggleForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        GcToggleRuntime::ensureLinked($ctx);

        foreach (['phpc_gc_enable', 'phpc_gc_disable', 'phpc_gc_is_enabled'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }

        $this->assertNull($ctx->module->getNamedGlobal('phpc_gc_enabled'));
    }
}
