<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\Hebrev;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #10529: hebrev standalone init compiles HebrevJitHelper under NestedJitCompileScope.
 *
 * @group aot-lint
 */
final class HebrevStandaloneTest extends TestCase
{
    public function testEnsureStandaloneBodiesCompilesHelper(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        Hebrev::ensureStandaloneBodies($ctx);
        Hebrev::helperFunction($ctx);

        $this->assertNotNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\standard\\HebrevJitHelper::convert')] ?? null
        );
    }
}
