<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #21925 / #31726: Zend gen-0 selfhost AOT must exit 0 after a successful link.
 * Host PHP used to abort in LLVM FFI teardown (`free(): invalid pointer`) or spin at
 * ~100% CPU for 15+ min even when build/selfhost was already written and runnable.
 */
final class SelfhostAotFastExitTest extends TestCase
{
    public function testAotEmitFastExitHelperExists(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/lib/AOT/AotEmitFastExit.php');
        $this->assertStringContainsString('void _exit(int status);', $helper);
        $this->assertStringContainsString('PHP_COMPILER_AOT_NO_FAST_EXIT', $helper);
        $this->assertStringContainsString('libc.so.6', $helper);
        $this->assertStringContainsString('#31726', $helper);
        $this->assertStringContainsString('function warmup', $helper);
        $this->assertStringContainsString('function exitAfterSuccessfulSelfhostEmit', $helper);
    }

    public function testCompilePhpWarmsAndDelegatesFastExit(): void
    {
        $root = dirname(__DIR__, 2);
        $compile = (string) file_get_contents($root.'/bin/compile.php');
        $this->assertStringContainsString('AotEmitFastExit::warmup', $compile);
        $this->assertStringContainsString('AotEmitFastExit::exitAfterSuccessfulSelfhostEmit', $compile);
        $this->assertStringContainsString('#31726', $compile);
    }

    public function testRuntimeStandaloneFastExitsAfterCompileToFile(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = (string) file_get_contents($root.'/lib/Runtime.php');
        $this->assertStringContainsString('AotEmitFastExit::warmup', $runtime);
        $this->assertStringContainsString('AotEmitFastExit::exitAfterSuccessfulSelfhostEmit', $runtime);
        $this->assertStringContainsString('runtime_standalone_compiletofile_done', $runtime);
    }

    /** Issue #31726: fast-exit must run before Runtime::standalone() returns (context dtor hang). */
    public function testRuntimeStandaloneFastExitsBeforeContextDestructor(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = (string) file_get_contents($root.'/lib/Runtime.php');
        $helper = (string) file_get_contents($root.'/lib/AOT/AotEmitFastExit.php');
        $this->assertStringContainsString('AotEmitFastExit::exitAfterSuccessfulSelfhostEmit', $runtime);
        $this->assertStringContainsString('runtime_standalone_fast_exit', $helper);
        $this->assertStringContainsString('PHP_COMPILER_AOT_NO_FAST_EXIT', $helper);
        $this->assertStringContainsString('PHP_COMPILER_SELFHOST_AOT', $helper);
        $this->assertMatchesRegularExpression(
            '/compiletofile_done.*exitAfterSuccessfulSelfhostEmit/s',
            $runtime
        );
    }

    public function testBuilderDisposeIsIdempotent(): void
    {
        $root = dirname(__DIR__, 2);
        $builder = (string) file_get_contents(
            $root.'/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php'
        );
        $this->assertStringContainsString('private bool $disposed = false;', $builder);
        $this->assertMatchesRegularExpression(
            '/function dispose\(\): void \{\s*if \(\$this->disposed\)/',
            $builder
        );
    }

    public function testSelfhostLinkRetriesFlakyBinaryRun(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/bootstrap-selfhost-link.sh');
        $this->assertStringContainsString('BOOTSTRAP_SELFHOST_RUN_TRIES', $script);
        $this->assertStringContainsString('#21925', $script);
    }

    public function testZendCompileInvokeHasPostEmitHangWatchdog(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/bootstrap-resolve-compile-invoke.sh');
        $this->assertStringContainsString('BOOTSTRAP_SELFHOST_POST_EMIT_GRACE_SEC', $script);
        $this->assertStringContainsString('runtime_standalone_compiletofile_done', $script);
        $this->assertStringContainsString('#31726', $script);
    }
}
