<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

/**
 * The function-body interval cache must be indistinguishable from the legacy scan (#23056).
 *
 * `isInsideFunctionBody` decides whether a `$var = …` is a local inside a method body or a hooked
 * property declaration. It runs during source preprocessing, so a behaviour change here alters how
 * every file in the project parses. Speed is worthless if the answer moves — these tests compare
 * cached against legacy on real corpus files at every plausible offset.
 */
final class PropertyHooksFunctionBodyCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_FUNCTION_BODY_SCAN_LEGACY');
    }

    /** @return \Closure(string, int): bool */
    private function prober(bool $legacy): \Closure
    {
        $hooks = new PropertyHooks();
        $ref = new \ReflectionMethod($hooks, 'isInsideFunctionBody');
        $ref->setAccessible(true);

        return static function (string $body, int $offset) use ($hooks, $ref, $legacy): bool {
            if ($legacy) {
                putenv('PHP_COMPILER_FUNCTION_BODY_SCAN_LEGACY=1');
            } else {
                putenv('PHP_COMPILER_FUNCTION_BODY_SCAN_LEGACY');
            }

            return (bool) $ref->invoke($hooks, $body, $offset);
        };
    }

    /** @return list<array{0: string, 1: string}> */
    public static function corpusProvider(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [
            'lib/VM.php',
            'lib/Compiler.php',
            'lib/SourcePreprocessor/PropertyHooks.php',
            'lib/JIT/Context.php',
            'ext/standard/PackJitHelper.php',
        ];
        $out = [];
        foreach ($files as $rel) {
            $abs = $root.'/'.$rel;
            if (is_readable($abs)) {
                $out[$rel] = [$rel, (string) file_get_contents($abs)];
            }
        }

        return $out;
    }

    /**
     * @dataProvider corpusProvider
     */
    public function testCachedMatchesLegacyAtEveryVarOffset(string $rel, string $body): void
    {
        $legacy = $this->prober(true);
        $cached = $this->prober(false);

        // The real call site probes `$var` occurrences, so compare exactly there.
        $offsets = [];
        $pos = 0;
        while (false !== ($pos = strpos($body, '$', $pos))) {
            $offsets[] = $pos;
            ++$pos;
        }
        $this->assertNotEmpty($offsets, "{$rel}: expected \$var occurrences");

        $checked = 0;
        foreach ($offsets as $offset) {
            $want = $legacy($body, $offset);
            $have = $cached($body, $offset);
            if ($want !== $have) {
                $this->fail(sprintf(
                    "%s: offset %d — legacy=%s cached=%s\ncontext: %s",
                    $rel,
                    $offset,
                    var_export($want, true),
                    var_export($have, true),
                    str_replace("\n", '\n', substr($body, max(0, $offset - 60), 120))
                ));
            }
            ++$checked;
        }
        $this->assertGreaterThan(0, $checked);
    }

    /** Brace boundaries are where an off-by-one would hide. */
    public function testCachedMatchesLegacyAtEveryBraceBoundary(): void
    {
        $body = (string) file_get_contents(\dirname(__DIR__, 2).'/lib/SourcePreprocessor/PropertyHooks.php');
        $legacy = $this->prober(true);
        $cached = $this->prober(false);

        $len = \strlen($body);
        for ($i = 0; $i < $len; ++$i) {
            if ('{' !== $body[$i] && '}' !== $body[$i]) {
                continue;
            }
            foreach ([$i - 1, $i, $i + 1, $i + 2] as $offset) {
                if ($offset < 0 || $offset > $len) {
                    continue;
                }
                $this->assertSame(
                    $legacy($body, $offset),
                    $cached($body, $offset),
                    "brace-adjacent offset {$offset} (brace at {$i})"
                );
            }
        }
    }

    /** Shapes the legacy scan treats specifically — nested blocks are NOT "inside a function body". */
    public function testKnownShapes(): void
    {
        $legacy = $this->prober(true);
        $cached = $this->prober(false);

        $samples = [
            'class body' => "<?php\nclass C {\n    \$x = 1;\n}\n",
            'method body' => "<?php\nclass C {\n    function f() {\n        \$x = 1;\n    }\n}\n",
            'nested block in method' => "<?php\nclass C {\n    function f() {\n        if (true) {\n            \$x = 1;\n        }\n    }\n}\n",
            'closure' => "<?php\n\$f = function () {\n    \$x = 1;\n};\n",
            'return type' => "<?php\nclass C {\n    function f(): ?array {\n        \$x = 1;\n    }\n}\n",
            'by-ref' => "<?php\nclass C {\n    function &f() {\n        \$x = 1;\n    }\n}\n",
            'no braces' => "<?php\n\$x = 1;\n",
        ];

        foreach ($samples as $label => $body) {
            $len = \strlen($body);
            for ($offset = 0; $offset <= $len; ++$offset) {
                $this->assertSame(
                    $legacy($body, $offset),
                    $cached($body, $offset),
                    "{$label} @ {$offset}"
                );
            }
        }
    }
}
