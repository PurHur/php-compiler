<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on empty copy/chown/chgrp compiler ABI shells from Builtin\Type (#32466).
 *
 * User-script copy()/chown()/chgrp() stay PHP helpers.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadCopyChownAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_copy',
            '__compiler_chown',
            '__compiler_chgrp',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnCopyChownAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32466', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32466)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32466)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_touch'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_move_uploaded_file'", $type);
        $this->assertStringContainsString('CopyRuntime::ensureLinked', (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php'
        ));
        $this->assertStringContainsString('ChownRuntime::ensureLinked', (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php'
        ));
    }

    public function testRuntimeOwnersDeclareCopyChownAbisModuleLocally(): void
    {
        $copy = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CopyRuntime.php');
        $this->assertStringContainsString("'__compiler_copy'", $copy);
        $this->assertStringContainsString("getNamedFunction('__compiler_copy')", $copy);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $copy);

        $chown = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ChownRuntime.php');
        $this->assertStringContainsString("'__compiler_chown'", $chown);
        $this->assertStringContainsString("'__compiler_chgrp'", $chown);
        $this->assertStringContainsString("getNamedFunction('__compiler_chown')", $chown);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $chown);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'CopyRuntime::ensureLinked',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitCopy.php')
        );
        $this->assertStringContainsString(
            'CopyJitHelper::copyArgv',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CopyRuntime.php')
        );
        $this->assertStringContainsString(
            'ChownRuntime::ensureLinked',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitChown.php')
        );
        $this->assertStringContainsString(
            'ChownRuntime::ensureLinked',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitChgrp.php')
        );
        $this->assertStringContainsString(
            'ChownJitHelper::chownArgv',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ChownRuntime.php')
        );
    }
}
