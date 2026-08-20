<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on utf8_encode / utf8_decode ABI shells from Builtin\Type (#32879).
 *
 * NestedJIT/AOT bridge stays StringUtf8Latin1.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint utf8_encode.1 (#31894 / #32122).
 */
final class TypeDeadUtf8Latin1AbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_utf8_encode',
            '__compiler_utf8_decode',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnUtf8Latin1Abis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32879', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32879)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32879)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_get_headers'", $type);
        $this->assertStringContainsString('StringUtf8Latin1::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresUtf8Latin1AbisModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Latin1.php');
        $this->assertStringContainsString('#32879', $svc);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $svc);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $svc);
        $this->assertStringContainsString('VmUtf8Latin1.php', $svc);
        $this->assertStringContainsString('getNamedFunction', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc);
        }
    }

    public function testTypeInitializeStillEnsureLinksUtf8Latin1Runtime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringUtf8Latin1::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltin(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/Utf8Latin1JitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/VmUtf8Latin1.php');
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/Utf8Latin1JitHelper.php');
        $this->assertStringContainsString('VmUtf8Latin1::encode', $helper);
        $this->assertStringContainsString('VmUtf8Latin1::decode', $helper);
        $this->assertStringContainsString('decodeArgv', $helper);
        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString('utf8latin1jithelper::encode', $cache);
        $this->assertStringContainsString('#32879', $cache);
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/utf8_encode.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_utf8_encode.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/runtime/utf8_encode.c'
        );
    }
}
