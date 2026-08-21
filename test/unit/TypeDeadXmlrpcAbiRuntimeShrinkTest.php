<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on xmlrpc ABI shells from Builtin\Type (#32902).
 *
 * NestedJIT/AOT bridges stay StringXmlrpc / ext/xmlrpc/JitXmlrpc.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint xmlrpc_encode.1 (#31894 / #32122).
 */
final class TypeDeadXmlrpcAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_xmlrpc_encode_value',
            '__compiler_xmlrpc_decode',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnXmlrpcAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32902', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32902)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32902)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('StringXmlrpc::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresXmlrpcAbisModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringXmlrpc.php');
        $this->assertStringContainsString('#32902', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $owner, "{$sym} must remain owned by StringXmlrpc (#32902)");
        }
        $jit = (string) file_get_contents(__DIR__.'/../../ext/xmlrpc/JitXmlrpc.php');
        $this->assertStringContainsString('StringXmlrpc::ensureEncodeLinked', $jit);
        $this->assertStringContainsString('StringXmlrpc::ensureDecodeLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksXmlrpcRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringXmlrpc::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForXmlrpcAbis(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlrpc.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/xmlrpc.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlrpc_encode.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/xmlrpc_encode.c');
    }
}
