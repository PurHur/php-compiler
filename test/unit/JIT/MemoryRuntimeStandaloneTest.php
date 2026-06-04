<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\MemoryRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5377: AOT standalone must define memory helpers without phpc_memory.c.
 *
 * @group aot-lint
 */
final class MemoryRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesMemoryRuntimeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        MemoryRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction(MemoryRuntime::READ_RSS);
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());

        $this->assertNotNull($ctx->module->getNamedGlobal(MemoryRuntime::GLOBAL_PEAK_EMALLOC));
        $this->assertNotNull($ctx->module->getNamedGlobal(MemoryRuntime::GLOBAL_PEAK_REAL));
    }
}
