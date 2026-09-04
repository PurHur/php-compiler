<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * Gen-0 chunk link: combine + helper-slug executable path (#36387).
 */
final class BootstrapGen0ChunkLinkScriptTest extends TestCase
{
    public function testChunkLinkScriptContracts(): void
    {
        $root = dirname(__DIR__, 2);
        $sh = (string) file_get_contents($root.'/script/bootstrap-gen0-chunk-link.sh');
        $php = (string) file_get_contents($root.'/script/bootstrap-gen0-chunk-link.php');
        $this->assertStringContainsString('bootstrap-gen0-chunk-link.php', $sh);
        $this->assertStringContainsString('CHUNK_LINK_EXECUTABLE', $sh);
        $this->assertStringContainsString('link.receipt.json', $sh);
        $this->assertStringContainsString('fresh receipt — skip', $sh);
        $this->assertStringContainsString('combineRelocatableObjects', $php);
        $this->assertStringContainsString('adoptUnitSlugsForLink', $php);
        $this->assertStringContainsString('helper_slugs', $php);
        $this->assertStringContainsString('LinkerProcessPolyfill', $php);
    }

    public function testChunkEmitExportsHelperSlugsAndKeepsBinaryPath(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/bootstrap-gen0-chunk-emit.sh');
        $this->assertStringContainsString('PHP_COMPILER_HELPER_SLUGS_EXPORT', $script);
        $this->assertStringContainsString('helpers.json', $script);
        $this->assertStringContainsString('helper_slug_count', $script);
        // LINK_BINARY must not rename the executable onto the .o path.
        $this->assertStringContainsString('Never rename a successful LINK_BINARY', $script);
        $this->assertStringContainsString('keep_object" == "1" && -f "${effective}"', $script);
    }

    public function testChunksOrchestratorOptionalLinkAfter(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/bootstrap-gen0-chunks.sh');
        $this->assertStringContainsString('CHUNK_LINK_AFTER', $script);
        $this->assertStringContainsString('bootstrap-gen0-chunk-link.sh', $script);
    }

    public function testContextExportsHelperSlugsOnObjectOnlyEmit(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString('PHP_COMPILER_HELPER_SLUGS_EXPORT', $src);
        $this->assertStringContainsString('usedUnitSlugs', $src);
    }

    public function testLinkerResolvesExtraScriptObjects(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/AOT/Linker.php');
        $this->assertStringContainsString('PHP_COMPILER_EXTRA_SCRIPT_OBJECTS', $src);
        $this->assertStringContainsString('resolveExtraScriptObjects', $src);
    }
}
