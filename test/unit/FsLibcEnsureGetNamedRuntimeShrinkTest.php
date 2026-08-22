<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * FS NestedJIT libc ensureLibc must getNamedFunction before addFunction
 * (#33774 / peer #33550 / #33650 / #31894 / #32122 name.1 mint class).
 *
 * @group aot-lint
 */
final class FsLibcEnsureGetNamedRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function owners(): array
    {
        return [
            'TouchLibcRuntime.php',
            'MkdirLibcRuntime.php',
            'CopyLibcRuntime.php',
            'ChownLibcRuntime.php',
            'ChmodLibcRuntime.php',
            'UnlinkLibcRuntime.php',
            'RealpathLibcRuntime.php',
        ];
    }

    private function ensureLibcBody(string $src): string
    {
        if (!preg_match(
            '/private static function ensureLibc\s*\(/',
            $src,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            $this->fail('ensureLibc not found');
        }
        $start = (int) $m[0][1];
        $brace = strpos($src, '{', $start);
        $this->assertNotFalse($brace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $brace; $i < $len; ++$i) {
            $ch = $src[$i];
            if ('{' === $ch) {
                ++$depth;
            } elseif ('}' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return substr($src, $brace + 1, $i - $brace - 1);
                }
            }
        }
        $this->fail('unbalanced braces in ensureLibc');
    }

    public function testFsLibcEnsureLibcRoutesThroughLibcExtern(): void
    {
        foreach ($this->owners() as $base) {
            $src = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/'.$base);
            $this->assertStringContainsString('#33774', $src, "{$base} must cite #33774");
            $body = $this->ensureLibcBody($src);
            $this->assertStringNotContainsString(
                "module->addFunction(\$name, \$ft);\n            \$context->registerFunction(\$name, \$fn);",
                $body,
                "{$base} must not bare-addFunction after lookup miss (#33774)"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/catch\s*\([^)]*\)\s*\{\s*\$\w+\s*=\s*\$context->module->addFunction/s',
                $body,
                "{$base}::ensureLibc must not catch→addFunction (#33774)"
            );
            $this->assertTrue(
                str_contains($body, 'LibcExtern::ensureExternalDecl')
                    || str_contains($body, 'LibcExtern::ensurePosixFd')
                    || str_contains($body, 'LibcExtern::ensureChownFamily')
                    || str_contains($body, 'LibcExtern::ensureStrlenDecl'),
                "{$base}::ensureLibc must call LibcExtern getNamed helpers (#33774)"
            );
        }
    }

    public function testTouchUsesPosixFdAndCopyKeepsStdio(): void
    {
        $touch = (string) file_get_contents(
            $this->repoRoot.'/lib/JIT/Builtin/TouchLibcRuntime.php'
        );
        $this->assertStringContainsString('LibcExtern::ensurePosixFd', $touch);
        $mkdir = (string) file_get_contents(
            $this->repoRoot.'/lib/JIT/Builtin/MkdirLibcRuntime.php'
        );
        $this->assertStringContainsString('LibcExtern::ensureStrlenDecl', $mkdir);
        $this->assertStringContainsString('LibcExtern::ensureMemcpyDecl', $mkdir);
        $this->assertStringContainsString('LibcExtern::ensureErrnoLocationDecl', $mkdir);
        $copy = (string) file_get_contents(
            $this->repoRoot.'/lib/JIT/Builtin/CopyLibcRuntime.php'
        );
        $this->assertStringContainsString('LibcExtern::ensureStdioFile', $copy);
        $chown = (string) file_get_contents(
            $this->repoRoot.'/lib/JIT/Builtin/ChownLibcRuntime.php'
        );
        $this->assertStringContainsString('LibcExtern::ensureChownFamily', $chown);
        $this->assertStringContainsString('LibcExtern::ensureStrtolDecl', $chown);
    }

    public function testThinFsLibcPeersUseLibcExtern(): void
    {
        $rmdir = (string) file_get_contents(
            $this->repoRoot.'/lib/JIT/Builtin/RmdirLibcRuntime.php'
        );
        $this->assertStringContainsString('LibcExtern::ensureRmdir', $rmdir);
        $umask = (string) file_get_contents(
            $this->repoRoot.'/lib/JIT/Builtin/UmaskLibcRuntime.php'
        );
        $this->assertStringContainsString('LibcExtern::ensureUmask', $umask);
        $chmod = (string) file_get_contents(
            $this->repoRoot.'/lib/JIT/Builtin/ChmodLibcRuntime.php'
        );
        $this->assertStringContainsString('LibcExtern::ensureExternalDecl', $chmod);
        $unlink = (string) file_get_contents(
            $this->repoRoot.'/lib/JIT/Builtin/UnlinkLibcRuntime.php'
        );
        $this->assertStringContainsString('LibcExtern::ensureExternalDecl', $unlink);
        $realpath = (string) file_get_contents(
            $this->repoRoot.'/lib/JIT/Builtin/RealpathLibcRuntime.php'
        );
        $this->assertStringContainsString('LibcExtern::ensureExternalDecl', $realpath);
    }

    public function testNoNewRuntimeCForFsLibcEnsure(): void
    {
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/touch_libc.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/runtime/touch_libc.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/mkdir_libc.c');
    }
}
