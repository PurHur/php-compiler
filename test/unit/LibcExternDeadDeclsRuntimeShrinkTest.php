<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * LibcExtern drops dead FS/string/process decls after helper migrations (#28850).
 *
 * Peer of {@see LibcExternMathRuntimeShrinkTest} (#28808).
 */
final class LibcExternDeadDeclsRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function deletedDecls(): array
    {
        return [
            'calloc',
            'strspn',
            'strpbrk',
            'strncpy',
            'utime',
            'chown',
            'fchownat',
            'getgrnam',
            'getpwnam',
            'remove',
            'pipe',
            'fork',
            'dup2',
            'waitpid',
            'flock',
            'fsync',
            'fdatasync',
            'sscanf',
        ];
    }

    public function testLibcExternDropsDeadDecls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        foreach ($this->deletedDecls() as $sym) {
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $source,
                "LibcExtern must not declare libc {$sym} (#28850)"
            );
        }
        $this->assertStringContainsString('#28850', $source);
    }

    public function testLibcExternKeepsLiveFsAndMcjitAliases(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        foreach (['rename', 'chdir', 'chmod', 'strcspn', 'syscall', '__phpc_host_php_write', '__phpc_host_snprintf'] as $sym) {
            $this->assertStringContainsString(
                "'{$sym}' =>",
                $source,
                "LibcExtern must keep live {$sym} (#28850)"
            );
        }
    }

    public function testChownRuntimeDoesNotLookupLibcChown(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ChownRuntime.php');
        $this->assertStringContainsString('ChownJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('chown')", $source);
        $this->assertStringNotContainsString("lookupFunction('fchownat')", $source);
        $this->assertStringNotContainsString("lookupFunction('getgrnam')", $source);
        $this->assertStringNotContainsString("lookupFunction('getpwnam')", $source);
    }

    public function testStringStrspnUsesPhpcAbiNotLibcStrspn(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrspn.php');
        $this->assertStringContainsString('phpc_strspn_extended', $source);
        $this->assertStringNotContainsString("lookupFunction('strspn')", $source);
    }
}
