<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Builtin ensureExternal must reuse existing module decls (getNamedFunction first)
 * via LibcExtern::ensureExternalDecl — never bare addFunction after lookupFunction miss
 * (#33550, re-#31894 / #32122 name.1 mint class).
 *
 * @group aot-lint
 */
final class EnsureExternalGetNamedRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function ensureExternalMethodBodies(string $src): array
    {
        $bodies = [];
        // Match ensureExternal(…), not ensureExternals(…).
        $offset = 0;
        while (preg_match(
            '/private static function ensureExternal\s*\(/',
            $src,
            $m,
            PREG_OFFSET_CAPTURE,
            $offset
        )) {
            $start = (int) $m[0][1];
            $brace = strpos($src, '{', $start);
            if (false === $brace) {
                break;
            }
            $depth = 0;
            $i = $brace;
            $len = strlen($src);
            for (; $i < $len; ++$i) {
                $ch = $src[$i];
                if ('{' === $ch) {
                    ++$depth;
                } elseif ('}' === $ch) {
                    --$depth;
                    if (0 === $depth) {
                        $bodies[] = substr($src, $brace + 1, $i - $brace - 1);
                        $offset = $i + 1;
                        break;
                    }
                }
            }
            if ($i >= $len) {
                break;
            }
        }

        return $bodies;
    }

    public function testBuiltinEnsureExternalRoutesThroughLibcExtern(): void
    {
        $builtinDir = $this->repoRoot.'/lib/JIT/Builtin';
        $bad = [];
        foreach (glob($builtinDir.'/*.php') ?: [] as $path) {
            $src = (string) file_get_contents($path);
            foreach ($this->ensureExternalMethodBodies($src) as $body) {
                if (!str_contains($body, 'LibcExtern::ensureExternalDecl')) {
                    $bad[] = basename($path);
                }
            }
        }
        $bad = array_values(array_unique($bad));
        $this->assertSame(
            [],
            $bad,
            'ensureExternal must call LibcExtern::ensureExternalDecl (#33550)'
        );
    }

    public function testSilenceLastErrorGcNoLongerMintLibcViaBareAddFunction(): void
    {
        foreach (['SilenceRuntime.php', 'LastErrorRuntime.php', 'GcCollectCyclesRuntime.php'] as $base) {
            $src = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/'.$base);
            $this->assertStringContainsString(
                'LibcExtern::ensureExternalDecl($context, $name, $ft)',
                $src,
                "{$base} ensureExternal must be LibcExtern::ensureExternalDecl (#33550)"
            );
            $this->assertStringNotContainsString(
                "module->addFunction(\$name, \$ft);\n            \$context->registerFunction(\$name, \$fn);",
                $src,
                "{$base} must not bare-addFunction after lookup miss (#33550)"
            );
        }
    }
}
