<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\JIT\SpineChunkRuntimeMethodDemote;
use PHPUnit\Framework\TestCase;

/**
 * Capacity proof: Compiler.php / JIT.php hollow under SPINE_CHUNK (#36387).
 *
 * Full object-only emit of these megabyte hubs still hits NestedJIT ARG_SEND /
 * OOM after SourceBundler mega-concat; they are plan-deferred when over max-bytes.
 * This test locks the pre-CFG hollow that shrinks the bundled unit.
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

    /**
     * @return list<array{0: string, 1: int}>
     */
    public static function hubSourcesProvider(): array
    {
        return [
            ['lib/Compiler.php', 500000],
            ['lib/JIT.php', 300000],
        ];
    }

    /**
     * @dataProvider hubSourcesProvider
     */
    public function testSpineChunkHollowShrinksMegabyteHub(string $rel, int $maxOutBytes): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.$rel;
        $this->assertFileExists($src);
        $code = (string) file_get_contents($src);
        $this->assertGreaterThan(500000, strlen($code));

        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        // Path-based and content-based: SourceBundler keeps entry filename, so content scan matters.
        $byPath = SpineChunkRuntimeMethodDemote::rewriteSource($code, $src);
        $bundled = "<?php\n// bundler marker\n".$code;
        $byBundleName = SpineChunkRuntimeMethodDemote::rewriteSource($bundled, '/tmp/chunk-entry.php');
        foreach ([$byPath, $byBundleName] as $hollowed) {
            $this->assertLessThan(strlen($code), strlen($hollowed));
            $this->assertLessThan($maxOutBytes, strlen($hollowed));
            $this->assertLessThan(substr_count($code, 'return '), substr_count($hollowed, 'return '));
        }
    }
}
