<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SuperglobalNameRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5391: AOT standalone must define __compiler_is_superglobal_name (PHP LLVM, not superglobal_name.c).
 *
 * @group aot-lint
 */
final class SuperglobalNameRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesSuperglobalNameForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        SuperglobalNameRuntime::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__compiler_is_superglobal_name');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
