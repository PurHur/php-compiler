<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringReadfile;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9188: readfile JIT bridge compiles ReadfileJitHelper, not libc open/read/write LLVM.
 *
 * @group aot-lint
 */
final class ReadfileRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesReadfileBridge(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringReadfile::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__compiler_readfile');
        $this->assertNotNull($fn, '__compiler_readfile must be linked for standalone AOT');
        $this->assertGreaterThan(0, $fn->countBasicBlocks(), '__compiler_readfile must have LLVM body');

        $lc = \strtolower('PHPCompiler\\ext\\standard\\ReadfileJitHelper::readfile');
        $this->assertArrayHasKey($lc, $ctx->functions, 'ReadfileJitHelper::readfile must be compiled into module');
    }
}
