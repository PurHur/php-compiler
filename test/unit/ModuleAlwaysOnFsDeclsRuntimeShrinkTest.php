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
        ];
    }

    /** @return list<string> */
    private function keptDecls(): array
    {
        return [
            'strlen',
            'stat',
            'access',
            'lstat',
            'mkdir',
            'chmod',
            'chroot',
            '__errno_location',
        ];
    }

    public function testModuleDropsDeadAlwaysOnFsDecls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringContainsString('#30530', $source);
        foreach ($this->deletedDecls() as $sym) {
            $this->assertStringNotContainsString(
                "lookupFunction('{$sym}')",
                $source,
                "Module.php must not always-declare libc {$sym} (#30530)"
            );
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $source,
                "Module.php must not always-add libc {$sym} (#30530)"
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
