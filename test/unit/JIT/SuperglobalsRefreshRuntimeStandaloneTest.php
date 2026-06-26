<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SuperglobalRefreshRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5330: AOT standalone must define __superglobals__refresh without superglobals_refresh.c.
 *
 * @group aot-lint
 */
final class SuperglobalsRefreshRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesSuperglobalRefresh(): void
    {
        $prev = getenv('PHP_COMPILER_SUPERGLOBAL_REFRESH_PHP');
        putenv('PHP_COMPILER_SUPERGLOBAL_REFRESH_PHP=1');
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            SuperglobalRefreshRuntime::ensureStandaloneBodies($ctx);

            $fn = $ctx->lookupFunction('__superglobals__refresh');
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_SUPERGLOBAL_REFRESH_PHP');
            } else {
                putenv('PHP_COMPILER_SUPERGLOBAL_REFRESH_PHP='.$prev);
            }
        }
    }

    public function testStandaloneDefaultUsesLlvmRefreshNotPhpBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/SuperglobalRefreshRuntime.php');
        $this->assertStringContainsString('PHP_COMPILER_SUPERGLOBAL_REFRESH_PHP', $source);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringContainsString('SuperglobalRefreshStandaloneLlvm::implement', $source);
    }

    public function testSuperglobalsRefreshCFileRemoved(): void
    {
        $this->assertFileDoesNotExist(
            __DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c',
            'superglobals_refresh.c must be deleted after LLVM migration (#5330)'
        );
    }
}
