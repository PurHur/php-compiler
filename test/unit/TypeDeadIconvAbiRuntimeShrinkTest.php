<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on empty iconv compiler ABI shell from Builtin\Type (#32482).
 *
 * User-script iconv() stays PHP helpers.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadIconvAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_iconv',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnIconvAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32482', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32482)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32482)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_http_build_query'", $type);
    }

    public function testRuntimeOwnerDeclaresIconvAbiModuleLocally(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IconvRuntime.php');
        $this->assertStringContainsString("'__compiler_iconv'", $runtime);
        $this->assertStringContainsString('getNamedFunction($name)', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('#32482', $runtime);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltin(): void
    {
        $this->assertStringContainsString(
            'IconvRuntimeLink::ensureLinked',
            (string) file_get_contents(__DIR__.'/../../ext/iconv/JitIconv.php')
        );
        $this->assertStringContainsString(
            "lookupFunction('__compiler_iconv')",
            (string) file_get_contents(__DIR__.'/../../ext/iconv/JitIconv.php')
        );
        $this->assertStringContainsString(
            'IconvJitHelper::convert',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IconvRuntime.php')
        );
        $this->assertStringContainsString(
            'function iconv(',
            (string) file_get_contents(__DIR__.'/../../ext/iconv/VmIconv.php')
        );
    }
}
