<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * libc leaf ensureCompiler*Decl must getNamedFunction before addFunction
 * (#33650 / peer #33550 / #31894 / #32122 name.1 mint class).
 *
 * @group aot-lint
 */
final class LibcLeafGetNamedRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    /** @return list<array{0: string, 1: string, 2: string}> file, method fragment, libc symbol */
    private function leafSpecs(): array
    {
        return [
            ['StringSymlink.php', 'ensureCompilerSymlinkDecl', 'symlink'],
            ['StringLink.php', 'ensureCompilerLinkDecl', 'link'],
            ['StringRename.php', 'ensureCompilerRenameDecl', 'rename'],
            ['StringChroot.php', 'ensureCompilerChrootDecl', 'chroot'],
            ['StringFnmatch.php', 'ensureCompilerFnmatchDecl', 'fnmatch'],
            ['GetcwdJit.php', 'ensureCompilerGetcwdDecl', 'getcwd'],
        ];
    }

    private function methodBody(string $src, string $method): string
    {
        if (!preg_match(
            '/private static function '.$method.'\s*\(/',
            $src,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            $this->fail("method {$method} not found");
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
        $this->fail("unbalanced braces in {$method}");
    }

    public function testLibcLeafDeclsGetNamedFunctionBeforeAddFunction(): void
    {
        foreach ($this->leafSpecs() as [$file, $method, $symbol]) {
            $src = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/'.$file);
            $body = $this->methodBody($src, $method);
            $this->assertStringContainsString(
                "getNamedFunction('{$symbol}')",
                $body,
                "{$file}::{$method} must getNamedFunction('{$symbol}') before addFunction (#33650)"
            );
            $addPos = strpos($body, "addFunction('{$symbol}'");
            $namedPos = strpos($body, "getNamedFunction('{$symbol}')");
            $this->assertNotFalse($addPos, "{$file} still declares {$symbol}");
            $this->assertNotFalse($namedPos);
            $this->assertLessThan(
                $addPos,
                $namedPos,
                "{$file}::{$method} getNamedFunction must precede addFunction (#33650)"
            );
        }
    }

    public function testStreamNotificationReusesEmptyProbe(): void
    {
        $src = (string) file_get_contents(
            $this->repoRoot.'/lib/JIT/Builtin/StreamNotificationRuntime.php'
        );
        $this->assertMatchesRegularExpression(
            '/\$fn\s*=\s*null\s*!==\s*\$probe\s*\?\s*\$probe\s*:\s*\$context->module->addFunction/',
            $src,
            'StreamNotificationRuntime must reuse empty probe (#33650)'
        );
    }
}
