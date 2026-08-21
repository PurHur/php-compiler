<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Guard against LLVM name.N mint from ensureExternal addFunction-without-getNamedFunction.
 *
 * Peer: #31894 GcCollectCyclesRuntime / LibcExtern::ensureExternalDecl.
 * Leftover class: #33550 (re-#32122 strlen.1 / free.1 / memset.1).
 */
final class BuiltinEnsureExternalNoDuplicateMintTest extends TestCase
{
    public function testBuiltinEnsureExternalRoutesThroughLibcExternOrGetNamedFunction(): void
    {
        $root = dirname(__DIR__, 3).'/lib/JIT/Builtin';
        $bad = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $text = (string) file_get_contents($path);
            if (!preg_match_all(
                '/private static function ensureExternal\([^)]*\): void\s*\{(.*?)\n    \}/s',
                $text,
                $matches,
                PREG_SET_ORDER
            )) {
                continue;
            }
            foreach ($matches as $match) {
                $body = $match[1];
                $usesLibc = str_contains($body, 'LibcExtern::ensureExternalDecl');
                $usesGetNamed = str_contains($body, 'getNamedFunction');
                $usesAdd = str_contains($body, 'addFunction');
                if ($usesAdd && !$usesGetNamed && !$usesLibc) {
                    $bad[] = substr($path, strlen(dirname(__DIR__, 3)) + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $bad,
            'Builtin ensureExternal must use LibcExtern::ensureExternalDecl '
            .'(or getNamedFunction before addFunction) — #33550 / #31894'
        );
    }

    public function testLibcExternEnsureExternalDeclIsGetNamedFunctionFirst(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('public static function ensureExternalDecl', $src);
        $this->assertMatchesRegularExpression(
            '/function ensureExternalDecl\(.*?\{.*?getNamedFunction\(\$name\).*?addFunction\(\$name/s',
            $src,
            'LibcExtern::ensureExternalDecl must getNamedFunction before addFunction (#31894)'
        );
    }
}
