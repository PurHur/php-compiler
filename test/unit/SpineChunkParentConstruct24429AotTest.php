<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPUnit\Framework\TestCase;

/**
 * SPINE_CHUNK: parent::__construct() to a missing parent ctor must not abort
 * compile with LogicException — fall through like unresolved instance methods (#24429).
 *
 * @group llvm
 * @group aot
 */
final class SpineChunkParentConstruct24429AotTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK);
        unset($_ENV[ExternalMethodBind::ENV_SPINE_CHUNK], $_SERVER[ExternalMethodBind::ENV_SPINE_CHUNK]);
        parent::tearDown();
    }

    public function testWithoutSpineChunkCompileAbortsCannotCallConstructor(): void
    {
        require_once dirname(__DIR__).'/LlvmToolchain.php';
        $root = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($root);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM unavailable');
        }
        $src = $root.'/test/repro/issue_24429_spine_chunk_parent_construct.php';
        $bin = sys_get_temp_dir().'/phpc_24429_no_chunk_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $out, $rc);
        @unlink($bin);
        $this->assertNotSame(0, $rc, implode("\n", $out));
        $this->assertStringContainsString('Cannot call constructor', implode("\n", $out));
    }

    public function testSpineChunkDoesNotAbortOnMissingParentConstruct(): void
    {
        require_once dirname(__DIR__).'/LlvmToolchain.php';
        $root = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($root);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM unavailable');
        }
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $_SERVER[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';

        $src = $root.'/test/repro/issue_24429_spine_chunk_parent_construct.php';
        $bin = sys_get_temp_dir().'/phpc_24429_chunk_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_SPINE_CHUNK=1 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertStringNotContainsString(
            'Cannot call constructor',
            $joined,
            'SPINE_CHUNK must fall through missing parent::__construct (#24429)'
        );
        $this->assertSame(0, $rc, $joined);
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            // ExternalMethod null for a missing ctor is a no-op — acceptable under
            // SPINE_CHUNK (probe goal is compile-past-abort, not Zend Error fidelity).
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("ok\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
