<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\session\SessionFileStorage;
use PHPCompiler\ext\standard\SessionGcJitHelper;
use PHPCompiler\ext\standard\VmIni;
use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** session_gc JIT routes through SessionGcJitHelper via JitVmHelperLink, not hand-rolled NestedJIT (#9411, #25916). */
final class SessionGcRuntimeShrinkTest extends TestCase
{
    private ?string $savedSessionDir = null;

    protected function setUp(): void
    {
        $dir = getenv('PHP_COMPILER_SESSION_DIR');
        $this->savedSessionDir = false !== $dir ? $dir : null;
        VmSession::reset();
    }

    protected function tearDown(): void
    {
        VmSession::reset();
        if (false === $this->savedSessionDir) {
            putenv('PHP_COMPILER_SESSION_DIR');
        } else {
            putenv('PHP_COMPILER_SESSION_DIR='.$this->savedSessionDir);
        }
        parent::tearDown();
    }

    public function testSessionGcRuntimeUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $gcRuntime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionGcRuntime.php');
        $this->assertStringContainsString('SessionGcJitHelper', $gcRuntime);
        $this->assertStringContainsString('gcExpiredFilesAsInt', $gcRuntime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $gcRuntime);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $gcRuntime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $gcRuntime);
        $this->assertStringNotContainsString('parseAndCompile', $gcRuntime);
        $this->assertStringNotContainsString('new JIT(', $gcRuntime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $gcRuntime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $gcRuntime);
        $this->assertStringNotContainsString('emitGcApply', $gcRuntime);
        $this->assertLessThan(230, \substr_count($gcRuntime, "\n") + 1);

        $storageRuntime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionStorageRuntime.php');
        $this->assertStringNotContainsString('ss_gc_loop_head', $storageRuntime);
        $this->assertStringNotContainsString('phpc_session_gc_expired_files', $storageRuntime);
    }

    public function testSessionGcJitHelperMatchesVmSessionGcExpiredFiles(): void
    {
        $dir = sys_get_temp_dir().'/phpc_sgc_helper_'.getmypid();
        @mkdir($dir, 0700, true);
        putenv('PHP_COMPILER_SESSION_DIR='.$dir);

        $runtime = new Runtime();
        VmIni::set($runtime->vmContext, 'session.gc_maxlifetime', '60');

        $stale = $dir.'/'.SessionFileStorage::PATH_PREFIX.'deadbeef';
        file_put_contents($stale, 'x');
        touch($stale, time() - 120);

        VmSession::reset();
        $code = <<<'PHP'
<?php
session_start();
PHP;
        $block = $runtime->parseAndCompile($code, 'sess_active.php');
        $runtime->run($block);

        $this->assertSame(1, SessionGcJitHelper::gcExpiredFilesAsInt());
        $this->assertFileDoesNotExist($stale);
    }
}
