<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on empty dir-handle compiler ABI shells from Builtin\Type (#32548).
 *
 * User-script opendir()/readdir()/closedir()/rewinddir() stay PHP helpers.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadDirAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_opendir',
            '__compiler_readdir',
            '__compiler_closedir',
            '__compiler_rewinddir',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnDirAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32548', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32548)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32548)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_http_build_query'", $type);
        $this->assertStringContainsString("addFunction('__compiler_is_resource'", $type);
    }

    public function testRuntimeOwnerDeclaresDirAbisModuleLocally(): void
    {
        $dir = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringDirRuntime.php');
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString("'{$sym}'", $dir);
        }
        $this->assertStringContainsString("getNamedFunction('__compiler_opendir')", $dir);
        $this->assertStringContainsString('DirHandleJitHelper', $dir);
        $this->assertStringContainsString('#11811', $dir);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'StringOpendir::invoke',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitOpendir.php')
        );
        $this->assertStringContainsString(
            'StringDir::ensureLinked',
            (string) file_get_contents(__DIR__.'/../../ext/standard/readdir.php')
        );
        $this->assertStringContainsString(
            "lookupFunction('__compiler_readdir')",
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitReaddir.php')
        );
        $this->assertStringContainsString(
            'StringDir::ensureLinked',
            (string) file_get_contents(__DIR__.'/../../ext/standard/closedir.php')
        );
        $this->assertStringContainsString(
            'StringDir::ensureLinked',
            (string) file_get_contents(__DIR__.'/../../ext/standard/rewinddir.php')
        );
    }
}
