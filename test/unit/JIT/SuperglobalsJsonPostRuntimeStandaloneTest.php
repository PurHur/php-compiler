<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SuperglobalRefreshRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #7389 / #9907: AOT standalone JSON POST via SuperglobalRefreshJitHelper PHP.
 *
 * @group aot-lint
 */
final class SuperglobalsJsonPostRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesSuperglobalRefresh(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        SuperglobalRefreshRuntime::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__superglobals__refresh');
        $this->assertNotNull($fn, '__superglobals__refresh must be linked for standalone AOT');
        $this->assertGreaterThan(0, $fn->countBasicBlocks(), '__superglobals__refresh must have LLVM body');
    }

    public function testSuperglobalsRefreshCFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
    }
}
