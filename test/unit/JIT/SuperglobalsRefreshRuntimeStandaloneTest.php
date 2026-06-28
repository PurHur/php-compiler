<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SuperglobalRefreshRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5330 / #13031: AOT standalone defines __superglobals__refresh via PHP bridge only.
 *
 * @group aot-lint
 */
final class SuperglobalsRefreshRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesSuperglobalRefresh(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        SuperglobalRefreshRuntime::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__superglobals__refresh');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }

    public function testSuperglobalRefreshRuntimeUsesPhpBridgeOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/SuperglobalRefreshRuntime.php');
        $this->assertStringContainsString('SuperglobalRefreshJitHelper', $source);
        $this->assertStringNotContainsString('SuperglobalRefreshStandaloneLlvm', $source);
    }

    public function testSuperglobalsRefreshCFileRemoved(): void
    {
        $this->assertFileDoesNotExist(
            __DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c',
            'superglobals_refresh.c must be deleted after LLVM migration (#5330)'
        );
    }
}
