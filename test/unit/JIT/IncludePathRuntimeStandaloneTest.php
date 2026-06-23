<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\IncludePathRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9245: AOT standalone include_path must use IncludePathJitHelper PHP, not phpc_include_path LLVM globals.
 *
 * @group aot-lint
 */
final class IncludePathRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesIncludePathBridgesForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        IncludePathRuntime::ensureLinked($ctx);

        foreach (
            [
                '__compiler_include_path_init',
                '__compiler_get_include_path',
                '__compiler_set_include_path',
                '__compiler_restore_include_path',
                '__compiler_stream_resolve_include_path',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }

        $this->assertNull($ctx->module->getNamedGlobal('phpc_include_path_depth'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_include_path_current'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_include_path_stack'));
    }
}
