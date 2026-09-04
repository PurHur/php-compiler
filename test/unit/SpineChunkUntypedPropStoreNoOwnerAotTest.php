<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPUnit\Framework\TestCase;

/**
 * SPINE_CHUNK: untyped object property writes with no in-TU declared owner must fall
 * through to stdClass dynamics — not LogicException "Property … not found" (#36387 / #36532).
 *
 * @group llvm
 * @group aot
 */
final class SpineChunkUntypedPropStoreNoOwnerAotTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK);
        unset($_ENV[ExternalMethodBind::ENV_SPINE_CHUNK], $_SERVER[ExternalMethodBind::ENV_SPINE_CHUNK]);
        parent::tearDown();
    }

    public function testSpineChunkCompilesUntypedPropStoreWithoutDeclaredOwner(): void
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

        $src = $root.'/test/repro/spine_chunk_untyped_prop_store_no_owner.php';
        $bin = sys_get_temp_dir().'/phpc_spine_prop_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_SPINE_CHUNK=1 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertStringNotContainsString(
            'Property isInternal not found on any declared class',
            $joined,
            'SPINE_CHUNK must not abort when no in-TU class owns the property (#36387)'
        );
        $this->assertSame(0, $rc, $joined);
        $this->assertFileExists($bin);
        // Compile-past-abort is the SPINE_CHUNK probe contract (#24429 / #36387).
        // Dynamic-prop runtime fidelity for undeclared names is a separate lowering track.
        @unlink($bin);
    }
}
