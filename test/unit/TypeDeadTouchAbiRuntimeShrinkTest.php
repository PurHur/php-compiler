<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on empty touch compiler ABI shell from Builtin\Type (#32510).
 *
 * User-script touch() stays PHP helpers.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadTouchAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_touch',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnTouchAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32510', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32510)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32510)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_http_build_query'", $type);
        $this->assertStringContainsString('FsDirRuntime::ensureLinked', (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php'
        ));
    }

    public function testRuntimeOwnerDeclaresTouchAbiModuleLocally(): void
    {
        $fsDir = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FsDirRuntime.php');
        $this->assertStringContainsString("'__compiler_touch'", $fsDir);
        $this->assertStringContainsString("getNamedFunction('__compiler_touch')", $fsDir);
        $this->assertStringContainsString('TouchLibcRuntime::emit', $fsDir);
        $this->assertStringContainsString('#32510', $fsDir);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltin(): void
    {
        $this->assertStringContainsString(
            'FsDirRuntime::ensureLinked',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitTouch.php')
        );
        $this->assertStringContainsString(
            "lookupFunction('__compiler_touch')",
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitTouch.php')
        );
        $this->assertStringContainsString(
            'function touch(',
            (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php')
        );
        $this->assertStringContainsString(
            "lookupFunction('utime')",
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TouchLibcRuntime.php')
        );
    }
}
