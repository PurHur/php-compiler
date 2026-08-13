<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Module.php drops dead always-on math libc decls after Math* helper migrations (#30666).
 *
 * Peer of {@see ModuleAlwaysOnFsDeclsRuntimeShrinkTest} (#30530) /
 * {@see LibcExternMathRuntimeShrinkTest} (#28808).
 */
final class ModuleAlwaysOnMathDeclsRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function deletedDecls(): array
    {
        return [
            'fabs',
            'round',
            'sqrt',
            'log',
            'log10',
            'exp',
            'expm1',
            'log1p',
            'sin',
            'cos',
            'tan',
            'acos',
            'asin',
            'atan',
            'sinh',
            'cosh',
            'tanh',
            'acosh',
            'asinh',
            'atanh',
            'pow',
            'hypot',
            'atan2',
            'modf',
            'frexp',
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
            '__errno_location',
        ];
    }

    public function testModuleDropsDeadAlwaysOnMathDecls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringContainsString('#30666', $source);
        foreach ($this->deletedDecls() as $sym) {
            $this->assertStringNotContainsString(
                "lookupFunction('{$sym}')",
                $source,
                "Module.php must not always-declare libc {$sym} (#30666)"
            );
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $source,
                "Module.php must not always-add libc {$sym} (#30666)"
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
