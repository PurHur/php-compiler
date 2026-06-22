<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\Highlight;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #10529: highlight_string standalone init compiles HighlightJitHelper under NestedJitCompileScope.
 *
 * @group aot-lint
 */
final class HighlightStandaloneTest extends TestCase
{
    public function testEnsureStandaloneBodiesCompilesHelper(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        Highlight::ensureStandaloneBodies($ctx);
        Highlight::helperFunction($ctx);

        $this->assertNotNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\standard\\HighlightJitHelper::renderString')] ?? null
        );
    }
}
