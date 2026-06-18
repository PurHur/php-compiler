<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StatCache;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9110: stat cache runtime symbols must LLVM-lower in standalone AOT mode.
 *
 * @group aot-lint
 */
final class StatCacheStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStatCacheHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StatCache::ensureLinked($ctx);

        foreach ([
            '__compiler_clearstatcache',
            '__phpc_jit_stat_mode_cached',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
