<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringIncludePathResolver;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #816: IncludePathResolver::resolve JIT bridge compiles IncludePathResolverJitHelper.
 *
 * @group aot-lint
 */
final class IncludePathResolverRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneCompilesIncludePathResolverHelper(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringIncludePathResolver::ensureStandaloneBodies($ctx);

        $lc = \strtolower('PHPCompiler\\ext\\standard\\IncludePathResolverJitHelper::resolve');
        $this->assertArrayHasKey($lc, $ctx->functions, 'IncludePathResolverJitHelper::resolve must be compiled into module');
    }
}
