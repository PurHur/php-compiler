<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\GethostbynamelRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5299: AOT standalone must define __compiler_gethostbynamel (PHP LLVM, not phpc_gethostbynamel.c).
 *
 * @group aot-lint
 */
final class GethostbynamelRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGethostbynamelForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        GethostbynamelRuntime::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__compiler_gethostbynamel');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
