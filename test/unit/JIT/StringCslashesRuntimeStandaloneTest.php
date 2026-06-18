<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringCslashes;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9578: cslashes JIT bridge compiles CslashesJitHelper, not mask/decode LLVM.
 *
 * @group aot-lint
 */
final class StringCslashesRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesCslashesBridges(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringCslashes::ensureStandaloneBodies($ctx);

        foreach (['__compiler_addcslashes', '__compiler_stripcslashes'] as $abi) {
            $fn = $ctx->lookupFunction($abi);
            $this->assertNotNull($fn, $abi.' must be linked for standalone AOT');
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $abi.' must have LLVM body');
        }

        foreach ([
            'PHPCompiler\\ext\\standard\\CslashesJitHelper::addcslashes',
            'PHPCompiler\\ext\\standard\\CslashesJitHelper::stripcslashes',
        ] as $logical) {
            $lc = \strtolower($logical);
            $this->assertArrayHasKey($lc, $ctx->functions, $logical.' must be compiled into module');
        }
    }
}
