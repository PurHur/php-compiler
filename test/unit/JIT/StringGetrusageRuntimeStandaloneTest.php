<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringGetrusage;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5388: AOT standalone must define getrusage helper without phpc_getrusage.c.
 *
 * @group aot-lint
 */
final class StringGetrusageRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGetrusageForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringGetrusage::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__compiler_getrusage');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
