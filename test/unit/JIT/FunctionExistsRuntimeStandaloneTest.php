<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\FunctionExistsRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5390: AOT standalone must define function_exists helpers without function_exists.c.
 *
 * @group aot-lint
 */
final class FunctionExistsRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesBuiltinLookupForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        FunctionExistsRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__compiler_builtin_function_exists');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
