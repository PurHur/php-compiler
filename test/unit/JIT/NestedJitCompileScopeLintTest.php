<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #10529: nested php-in-PHP JIT helper compiles during AOT standalone init must use NestedJitCompileScope.
 *
 * @group aot-lint
 */
final class NestedJitCompileScopeLintTest extends TestCase
{
    /**
     * Context::defineBuiltins standalone routes that call new JIT() during module init (#10498, #10529).
     *
     * @return list<string> repo-relative paths under lib/JIT/Builtin/
     */
    private static function standaloneNestedCompileBuiltinPaths(): array
    {
        return [
            'lib/JIT/Builtin/AssertOptionsRuntime.php',
            'lib/JIT/Builtin/StringStripTags.php',
            'lib/JIT/Builtin/StringGetenv.php',
            'lib/JIT/Builtin/ScalarDimFetchRuntime.php',
            'lib/JIT/Builtin/GcToggleRuntime.php',
            'lib/JIT/Builtin/ProgressNoteRuntime.php',
            'lib/JIT/Builtin/LastErrorRuntime.php',
            'lib/JIT/Builtin/RewriteVarsRuntime.php',
            'lib/JIT/Builtin/DefineRuntime.php',
            'lib/JIT/Builtin/TokenGetAll.php',
            'lib/JIT/Builtin/Highlight.php',
            'lib/JIT/Builtin/Hebrev.php',
            'lib/JIT/Builtin/ObGzhandlerJitRuntime.php',
        ];
    }

    public function testStandaloneBuiltinNestedCompilesUseNestedJitCompileScope(): void
    {
        $root = \dirname(__DIR__, 3);
        $violations = [];
        foreach (self::standaloneNestedCompileBuiltinPaths() as $rel) {
            $path = $root.'/'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) \file_get_contents($path);
            if (!\str_contains($source, 'new JIT(')) {
                continue;
            }
            if (\str_contains($source, 'NestedJitCompileScope')
                || \str_contains($source, 'JitVmHelperLink::')) {
                continue;
            }
            $violations[] = $rel;
        }
        $this->assertSame(
            [],
            $violations,
            "Nested JIT compiles must wrap new JIT() in NestedJitCompileScope::run (or JitVmHelperLink):\n"
            .\implode("\n", $violations)
        );
    }
}
