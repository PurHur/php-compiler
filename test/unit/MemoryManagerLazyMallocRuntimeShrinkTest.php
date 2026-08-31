<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop MemoryManager\Native::register always-on LibcExtern::ensureMallocFamily (#36100).
 *
 * Thin hello-world AOT must not declare libc malloc/realloc/free during type register —
 * Context::lookupFunction lazy-links on first __mm__* implement (peer #36074 setlocale).
 */
final class MemoryManagerLazyMallocRuntimeShrinkTest extends TestCase
{
    public function testMemoryManagerNativeRegisterDropsEagerMallocEnsure(): void
    {
        foreach ([
            __DIR__.'/../../lib/JIT/Builtin/MemoryManager/Native.php',
            __DIR__.'/../../lib/JIT/Builtin/MemoryManager/Native.pre',
        ] as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString('#36100', $source);
            $pos = strpos($source, 'public function register(): void');
            $this->assertNotFalse($pos);
            $next = strpos($source, 'public function implement(): void', $pos);
            $this->assertNotFalse($next);
            $body = substr($source, $pos, $next - $pos);
            $this->assertStringNotContainsString(
                'ensureMallocFamily',
                $body,
                basename($path).' register must not eagerly ensureMallocFamily (#36100)'
            );
        }
    }

    public function testContextLookupFunctionLazyLinksMallocFamily(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#36100', $context);
        $this->assertStringContainsString("LibcExtern::ensureMallocFamily(\$this)", $context);
        $this->assertMatchesRegularExpression(
            "/'malloc' === \\\$name \\|\\| 'realloc' === \\\$name \\|\\| 'free' === \\\$name/",
            $context
        );
    }

    public function testEnsureMallocFamilyUsesTryGetRegisteredFunctionNotLookup(): void
    {
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('#36100', $libc);
        $pos = strpos($libc, 'public static function ensureMallocFamily');
        $this->assertNotFalse($pos);
        $next = strpos($libc, 'public static function ensureResolveStreamDecl', $pos);
        $this->assertNotFalse($next);
        $body = substr($libc, $pos, $next - $pos);
        $this->assertStringContainsString('tryGetRegisteredFunction', $body);
        $this->assertStringNotContainsString('lookupFunction($name)', $body);
    }

    public function testNoNewRuntimeCForLazyMallocAbi(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'malloc_runtime.c',
            'phpc_malloc.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #36100 — LibcExtern::ensureMallocFamily only'
            );
        }
    }
}
