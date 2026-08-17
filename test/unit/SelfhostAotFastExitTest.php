<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #21925: Zend gen-0 selfhost AOT must exit 0 after a successful link.
 * Host PHP used to abort in LLVM FFI teardown (`free(): invalid pointer`) even
 * when build/selfhost was already written and runnable.
 */
final class SelfhostAotFastExitTest extends TestCase
{
    public function testCompilePhpFastExitsAfterSelfhostAotEmit(): void
    {
        $root = dirname(__DIR__, 2);
        $compile = (string) file_get_contents($root.'/bin/compile.php');
        $this->assertStringContainsString('PHP_COMPILER_AOT_NO_FAST_EXIT', $compile);
        $this->assertStringContainsString('void _exit(int status);', $compile);
        $this->assertStringContainsString('test/selfhost/', $compile);
        $this->assertStringContainsString('#21925', $compile);
    }

    /** Issue #31726: fast-exit must run before Runtime::standalone() returns (context dtor hang). */
    public function testRuntimeStandaloneFastExitsBeforeContextDestructor(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = (string) file_get_contents($root.'/lib/Runtime.php');
        $this->assertStringContainsString('runtime_standalone_fast_exit', $runtime);
        $this->assertStringContainsString('#31726', $runtime);
        $this->assertStringContainsString('PHP_COMPILER_AOT_NO_FAST_EXIT', $runtime);
        $this->assertMatchesRegularExpression(
            '/compiletofile_done.*runtime_standalone_fast_exit|_exit\(0\)/s',
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
}
