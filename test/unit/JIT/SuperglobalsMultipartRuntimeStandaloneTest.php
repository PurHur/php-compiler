<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SuperglobalRefreshRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #7302 / #9394 / #13031: multipart parsing is PHP SSOT via SuperglobalRefreshRuntime.
 *
 * @group aot-lint
 */
final class SuperglobalsMultipartRuntimeStandaloneTest extends TestCase
{
    public function testStandaloneRefreshUsesPhpBridgeWithoutMultipartLlvm(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        SuperglobalRefreshRuntime::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__superglobals__refresh');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());

        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringMultipartStandaloneLlvm.php');
    }

    public function testSuperglobalsRefreshCFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
    }
}
