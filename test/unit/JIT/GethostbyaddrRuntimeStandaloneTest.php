<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\GethostbyaddrRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5854: AOT standalone must define __compiler_gethostbyaddr (PHP LLVM).
 *
 * @group aot-lint
 */
final class GethostbyaddrRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGethostbyaddrForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        GethostbyaddrRuntime::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__compiler_gethostbyaddr');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
