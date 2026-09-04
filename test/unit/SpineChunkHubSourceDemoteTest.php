<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\JIT\SpineChunkRuntimeMethodDemote;
use PHPUnit\Framework\TestCase;

/**
 * SPINE_CHUNK pre-CFG hub source demote (#36387).
 *
 * @group aot-lint
 */
final class SpineChunkHubSourceDemoteTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK);
        unset($_ENV[ExternalMethodBind::ENV_SPINE_CHUNK], $_SERVER[ExternalMethodBind::ENV_SPINE_CHUNK]);
        parent::tearDown();
    }

    public function testFqcnFromLibPath(): void
    {
        $this->assertSame(
            'PHPCompiler\\Compiler',
            SpineChunkRuntimeMethodDemote::fqcnFromFilename('/compiler/lib/Compiler.php')
        );
        $this->assertSame(
            'PHPCompiler\\JIT',
            SpineChunkRuntimeMethodDemote::fqcnFromFilename('/compiler/lib/JIT.php')
        );
        $this->assertSame(
            'PHPCompiler\\JIT\\Analyzer',
            SpineChunkRuntimeMethodDemote::fqcnFromFilename('/app/lib/JIT/Analyzer.php')
        );
        $this->assertNull(SpineChunkRuntimeMethodDemote::fqcnFromFilename('/compiler/ext/dom/VmDom.php'));
    }

    public function testHollowLeavesSignaturesAndEmptiesBodies(): void
    {
        $src = <<<'PHP'
<?php
namespace PHPCompiler;
final class Block {
    public function foo(int $x): int {
        return $x + 1;
    }
    abstract public function bar();
    public function baz() {
        if (true) {
            echo "nested";
        }
    }
}
PHP;
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $out = SpineChunkRuntimeMethodDemote::hollowClassMethodBodies($src, 'Block');
        $this->assertStringContainsString('function foo(int $x): int {}', preg_replace('/\s+/', ' ', $out) ?? $out);
        $this->assertStringContainsString('abstract public function bar();', $out);
        $this->assertStringContainsString('function baz() {}', preg_replace('/\s+/', ' ', $out) ?? $out);
        $this->assertStringNotContainsString('return $x + 1', $out);
        $this->assertStringNotContainsString('nested', $out);
    }

    public function testRewriteHollowsBundledBracedNamespace(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        // SourceBundler shape: braced namespace, logical filename is the entry path.
        // Block is demoted; Compiler/JIT stay live (host CFG still OOMs).
        $src = <<<'PHP'
<?php
namespace PHPCompiler {
final class Block {
    public function f() { return 1; }
}
}
PHP;
        $hollowed = SpineChunkRuntimeMethodDemote::rewriteSource($src, '/tmp/entry.php');
        $this->assertStringNotContainsString('return 1', $hollowed);
        $this->assertStringContainsString('function f() {}', preg_replace('/\s+/', ' ', $hollowed) ?? $hollowed);
    }

    public function testRewriteOnlyUnderSpineChunkForDemotedHub(): void
    {
        $src = "<?php\nnamespace PHPCompiler;\nclass Block { public function f() { return 1; } }\n";
        $unchanged = SpineChunkRuntimeMethodDemote::rewriteSource($src, '/compiler/lib/Block.php');
        $this->assertSame($src, $unchanged);

        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $hollowed = SpineChunkRuntimeMethodDemote::rewriteSource($src, '/compiler/lib/Block.php');
        $this->assertStringNotContainsString('return 1', $hollowed);
        $this->assertStringContainsString('function f() {}', preg_replace('/\s+/', ' ', $hollowed) ?? $hollowed);

        // CompilerVersion stays live — no hollow.
        $cv = "<?php\nnamespace PHPCompiler;\nclass CompilerVersion { public function f() { return 1; } }\n";
        $this->assertSame($cv, SpineChunkRuntimeMethodDemote::rewriteSource($cv, '/compiler/lib/CompilerVersion.php'));
        // Compiler stays live (file-split needed) — no hollow.
        $compiler = "<?php\nnamespace PHPCompiler;\nclass Compiler { public function f() { return 1; } }\n";
        $this->assertSame($compiler, SpineChunkRuntimeMethodDemote::rewriteSource($compiler, '/compiler/lib/Compiler.php'));
    }
}
