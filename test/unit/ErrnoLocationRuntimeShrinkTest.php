<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Module.php drops always-on __errno_location after LibcExtern module-local (#32643).
 *
 * Peer of {@see ModuleAlwaysOnFsDeclsRuntimeShrinkTest} (#30530) /
 * {@see ProcNiceRuntimeShrinkTest} (#30615).
 */
final class ErrnoLocationRuntimeShrinkTest extends TestCase
{
    public function testModuleDropsAlwaysOnErrnoLocation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringContainsString('#32643', $source);
        $this->assertStringNotContainsString(
            "lookupFunction('__errno_location')",
            $source,
            'Module.php must not always-declare __errno_location (#32643)'
        );
        $this->assertStringNotContainsString(
            "addFunction('__errno_location'",
            $source,
            'Module.php must not always-add __errno_location (#32643)'
        );
    }

    public function testLibcExternOwnsErrnoLocationEnsure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureErrnoLocationDecl', $source);
        $this->assertStringContainsString('#32643', $source);
        $this->assertStringNotContainsString(
            "'__errno_location' =>",
            $source,
            'LibcExtern always-on table must not declare __errno_location (#32643)'
        );
    }

    public function testNestedJitConsumersEnsureErrnoLocationBeforeLookup(): void
    {
        foreach ([
            'lib/JIT/Builtin/StringProcNice.php',
            'ext/standard/JitFtok.php',
            'lib/JIT/Builtin/SemRuntime.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureErrnoLocationDecl',
                $source,
                "{$rel} must call LibcExtern::ensureErrnoLocationDecl (#32643)"
            );
            $this->assertStringContainsString('#32643', $source);
        }
    }

    public function testNoNestedJitLookupWithoutEnsureRemains(): void
    {
        $root = dirname(__DIR__, 2);
        $missing = [];
        foreach (['lib', 'ext'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                if (str_ends_with($path, '/lib/JIT/LibcExtern.php')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                if (!str_contains($source, "lookupFunction('__errno_location')")) {
                    continue;
                }
                if (!str_contains($source, 'LibcExtern::ensureErrnoLocationDecl')) {
                    $missing[] = substr($path, strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $missing, 'NestedJIT __errno_location lookups must call ensureErrnoLocationDecl (#32643)');
    }
}
