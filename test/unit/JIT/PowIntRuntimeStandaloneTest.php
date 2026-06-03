<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\PowIntRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5202: AOT standalone must define __phpc_pow_int (PHP LLVM, not phpc_pow.c).
 *
 * @group aot-lint
 */
final class PowIntRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesPowIntForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        PowIntRuntime::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__phpc_pow_int');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
