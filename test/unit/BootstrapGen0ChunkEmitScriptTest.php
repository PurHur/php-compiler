<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * Gen-0 chunk emit defaults: object-only + helper-runtime + SPINE_CHUNK (#36387).
 */
final class BootstrapGen0ChunkEmitScriptTest extends TestCase
{
    public function testChunkEmitDefaultsToObjectOnlyAndHelperRuntime(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/bootstrap-gen0-chunk-emit.sh');
        $this->assertStringContainsString('PHP_COMPILER_KEEP_OBJECT_FILE', $script);
        $this->assertStringContainsString('PHP_COMPILER_HELPER_RUNTIME_O', $script);
        $this->assertStringContainsString('CHUNK_LINK_BINARY', $script);
        $this->assertStringContainsString('object_only', $script);
        $this->assertMatchesRegularExpression(
            '/PHP_COMPILER_HELPER_RUNTIME_O="\$\{PHP_COMPILER_HELPER_RUNTIME_O:-1\}"/',
            $script
        );
        $this->assertStringContainsString('keep_object=1', $script);
        $this->assertStringContainsString('peer manifests', $script);
        $this->assertStringContainsString('PHP_COMPILER_EXTERNAL_METHOD_MANIFEST', $script);
    }
}
