<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop always-on libc getrandom(3) from Builtin\Type (#32139).
 *
 * User-script random_bytes() stays PHP-in-PHP (#29531). NestedJIT kernel
 * uses /dev/urandom open/read, not getrandom(3).
 */
final class GetrandomRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinNoLongerDeclaresAlwaysOnGetrandom(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringNotContainsString("addFunction('getrandom'", $type);
        $this->assertStringNotContainsString("registerFunction('getrandom'", $type);
        $this->assertStringContainsString('#32139', $type);
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
    }

    public function testNoNestedJitLookupFunctionGetrandomRemains(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        foreach (['lib', 'ext'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                $source = (string) file_get_contents($path);
                if (str_contains($source, "lookupFunction('getrandom')")
                    || str_contains($source, 'lookupFunction("getrandom")')) {
                    $hits[] = substr($path, strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $hits, 'No NestedJIT lookupFunction(\'getrandom\') may remain (#32139)');
    }

    public function testRandomBytesKernelUsesUrandomNotGetrandom(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRandomBytesKernel.php');
        $this->assertStringContainsString('/dev/urandom', $source);
        $this->assertStringContainsString("lookupFunction('open')", $source);
        $this->assertStringContainsString("lookupFunction('read')", $source);
        $this->assertStringNotContainsString("lookupFunction('getrandom')", $source);
        $this->assertStringContainsString('#29531', $source);
    }
}
