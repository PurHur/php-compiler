<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Module.php drops dead always-on FS libc decls after helper migrations (#30530).
 *
 * Peer of {@see LibcExternDeadDeclsRuntimeShrinkTest} (#28850) / fnmatch Module drop (#30383).
 */
final class ModuleAlwaysOnFsDeclsRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function deletedDecls(): array
    {
        return [
            'realpath',
            'statvfs',
            'readlink',
            'unlink',
            'rmdir',
            'nice',
            'chdir',
            'chroot', // #30558 — StringChroot NestedJIT leaf
            'mkdir', // #31374 — MkdirJitHelper + NestedJIT module-local
            'chmod', // #31374 — ChmodJitHelper + NestedJIT module-local
            'stat', // #31403 — JitStatKernel + NestedJIT module-local
            'access', // #31403
            'lstat', // #31403
            'strrchr', // #31458 — StrrchrJitHelper + NestedJIT module-local
        ];
    }

    /** @return list<string> */
    private function keptDecls(): array
    {
        return [
            'strlen',
            '__errno_location',
        ];
    }

    public function testModuleDropsDeadAlwaysOnFsDecls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringContainsString('#30530', $source);
        $this->assertStringContainsString('#31374', $source);
        $this->assertStringContainsString('#31403', $source);
        $this->assertStringContainsString('#31458', $source);
        foreach ($this->deletedDecls() as $sym) {
            $this->assertStringNotContainsString(
                "lookupFunction('{$sym}')",
                $source,
                "Module.php must not always-declare libc {$sym} (#30530/#31374/#31403/#31458)"
            );
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $source,
                "Module.php must not always-add libc {$sym} (#30530/#31374/#31403/#31458)"
            );
        }
    }

    public function testModuleKeepsLiveAlwaysOnFsDecls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        foreach ($this->keptDecls() as $sym) {
            $this->assertStringContainsString(
                "lookupFunction('{$sym}')",
                $source,
                "Module.php must still always-declare live libc {$sym}"
            );
        }
    }
}
