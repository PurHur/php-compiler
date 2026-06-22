<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\TokenGetAll;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #10529: token_get_all standalone init compiles TokenGetAllJitHelper under NestedJitCompileScope.
 *
 * @group aot-lint
 */
final class TokenGetAllStandaloneTest extends TestCase
{
    public function testEnsureStandaloneBodiesCompilesHelper(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        TokenGetAll::ensureStandaloneBodies($ctx);
        TokenGetAll::helperFunction($ctx);

        $this->assertNotNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\tokenizer\\TokenGetAllJitHelper::tokenizeToHashTable')] ?? null
        );
    }
}
