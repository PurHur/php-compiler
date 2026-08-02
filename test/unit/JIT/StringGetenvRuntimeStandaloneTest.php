<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5330: AOT standalone must define __compiler_getenv without superglobals_refresh.c C body.
 *
 * @group aot-lint
 */
final class StringGetenvRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneBodiesDefinesGetenvForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringGetenv::ensureStandaloneBodies($ctx);
        $fn = $ctx->lookupFunction('__compiler_getenv');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
        // Type.php declares __compiler_getenv before ensureStandaloneBodies; an empty
        // entry block must not satisfy "body present" (#26756 / argv-driver link).
        $this->assertTrue(
            JitVmHelperLink::hasNamedBridgeEntry($fn, 'getenv_bridge_entry'),
            '__compiler_getenv must have getenv_bridge_entry with a terminator'
        );

        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
    }
}
