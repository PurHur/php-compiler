<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\GetimagesizeJit;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #3271 / #25527: GetimagesizeJitHelper compiles into standalone AOT module.
 *
 * @group aot-lint
 */
final class GetimagesizeJitRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneCompilesGetimagesizeHelpers(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        GetimagesizeJit::ensureStandaloneBodies($ctx);

        foreach ([
            'PHPCompiler\\ext\\standard\\GetimagesizeJitHelper::fromBytes',
            'PHPCompiler\\ext\\standard\\GetimagesizeJitHelper::shouldEmitReadNoticeForPath',
            'PHPCompiler\\ext\\standard\\GetimagesizeJitHelper::shouldEmitReadNoticeForBytes',
        ] as $logical) {
            $lc = \strtolower($logical);
            $this->assertArrayHasKey($lc, $ctx->functions, $logical.' must be compiled into module');
        }
    }
}
