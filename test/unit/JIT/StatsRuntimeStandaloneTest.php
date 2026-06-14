<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Stats;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * stats_* LLVM helpers must lower without C symbols (#5748).
 *
 * @group aot-lint
 */
final class StatsRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStatsHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        Stats::ensureLinked($ctx);

        foreach ([
            '__compiler_stats_variance',
            '__compiler_stats_covariance',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
