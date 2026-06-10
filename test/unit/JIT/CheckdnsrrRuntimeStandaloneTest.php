<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\CheckdnsrrRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5983: AOT standalone must define __compiler_checkdnsrr (PHP LLVM, not C runtime).
 *
 * @group aot-lint
 */
final class CheckdnsrrRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesCheckdnsrrForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        CheckdnsrrRuntime::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__compiler_checkdnsrr');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
