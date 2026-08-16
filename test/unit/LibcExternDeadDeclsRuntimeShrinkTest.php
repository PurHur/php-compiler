<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * LibcExtern drops dead FS/string/process decls after helper migrations (#28850, #29050).
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
            'strcspn',
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
            'strtok_r',
            'rename',
            'chdir',
            'time',
            'chmod',
            'mkdir',
            'stat',
            'access',
            'lstat',
        ];
    }

    public function testLibcExternDropsDeadDecls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        foreach ($this->deletedDecls() as $sym) {
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $source,
                "LibcExtern must not declare libc {$sym} (#28850/#29050/#30332/#31374/#31403)"
            );
        }
        $this->assertStringContainsString('#28850', $source);
        $this->assertStringContainsString('#29050', $source);
        $this->assertStringContainsString('#30332', $source);
        $this->assertStringContainsString('#31374', $source);
        $this->assertStringContainsString('#31403', $source);
    }

    public function testLibcExternKeepsLiveFsAndMcjitAliases(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        foreach (['mkstemp', 'syscall', '__phpc_host_php_write', '__phpc_host_snprintf'] as $sym) {
            $this->assertStringContainsString(
                "'{$sym}' =>",
                $source,
                "LibcExtern must keep live {$sym} (#28850)"
            );
        }
        $this->assertStringNotContainsString(
            "'rename' =>",
            $source,
            'LibcExtern must not declare libc rename (#29090)'
        );
        $this->assertStringContainsString('#29090', $source);
        $this->assertStringNotContainsString(
            "'chdir' =>",
            $source,
            'LibcExtern must not declare libc chdir (#29219)'
        );
        $this->assertStringContainsString('#29219', $source);
        $this->assertStringNotContainsString(
            "'time' =>",
            $source,
            'LibcExtern must not declare libc time (#30332)'
        );
        $this->assertStringNotContainsString(
            "'chmod' =>",
            $source,
            'LibcExtern must not declare libc chmod (#31374)'
        );
        $this->assertStringNotContainsString(
            "'mkdir' =>",
            $source,
            'LibcExtern must not declare libc mkdir (#31374)'
        );
        $this->assertStringNotContainsString(
            "'stat' =>",
            $source,
            'LibcExtern must not declare libc stat (#31403)'
        );
        $this->assertStringNotContainsString(
            "'access' =>",
            $source,
            'LibcExtern must not declare libc access (#31403)'
        );
        $this->assertStringNotContainsString(
            "'lstat' =>",
            $source,
            'LibcExtern must not declare libc lstat (#31403)'
        );
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
