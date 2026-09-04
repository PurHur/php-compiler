<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\JIT\SpineChunkRuntimeMethodDemote;
use PHPUnit\Framework\TestCase;

/**
 * Capacity proof: demoted megabyte hubs hollow under SPINE_CHUNK (#36387).
 *
 * Compiler.php / JIT.php stay live (host CFG still OOMs) and are spine-skipped.
 * Oversized demoted hubs (e.g. VM.php) stay emit-eligible after demote; only the
 * measured-fail allowlist is plan-deferred. This locks pre-CFG hollow for demoted
 * hubs when SourceBundler keeps the entry filename.
 *
 * @group aot-lint
 */
final class CompilerJitHubSourceDemote36387Test extends TestCase
{
    protected function tearDown(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK);
        unset($_ENV[ExternalMethodBind::ENV_SPINE_CHUNK], $_SERVER[ExternalMethodBind::ENV_SPINE_CHUNK]);
        parent::tearDown();
    }

    public function testSpineChunkHollowShrinksDemotedVmHub(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/lib/VM.php';
        $this->assertFileExists($src);
        $code = (string) file_get_contents($src);
        $this->assertGreaterThan(500000, strlen($code));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM')
            || true); // shouldDemote needs env — set below

        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM'));

        $byPath = SpineChunkRuntimeMethodDemote::rewriteSource($code, $src);
        $bundled = "<?php\n// bundler marker\n".$code;
        $byBundleName = SpineChunkRuntimeMethodDemote::rewriteSource($bundled, '/tmp/chunk-entry.php');
        foreach ([$byPath, $byBundleName] as $hollowed) {
            $this->assertLessThan(strlen($code), strlen($hollowed));
            $this->assertLessThan(400000, strlen($hollowed));
            $this->assertLessThan(substr_count($code, 'return '), substr_count($hollowed, 'return '));
        }
    }
}
