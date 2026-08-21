<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on stream_socket_* ABI shells from Builtin\Type (#32807).
 *
 * User-script stream_socket_get_name()/stream_socket_accept() stay PHP helpers.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadStreamSocketAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_stream_socket_get_name',
            '__compiler_stream_socket_accept',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamSocketAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32807', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32807)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32807)"
            );
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $type,
                "Builtin\\Type must not always-declare {$sym} in a table (#32807)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (str_getcsv still Type always-on after #33199 preg_split drop).
        $this->assertStringContainsString("registerFunction('__compiler_str_getcsv'", $type);
        $this->assertStringContainsString('StreamSocketGetNameRuntime::ensureLinked', $type);
        $this->assertStringContainsString('StreamSocketAccept::ensureLinked', $type);
    }

    public function testRuntimeOwnersDeclareStreamSocketAbisModuleLocally(): void
    {
        $getName = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamSocketGetNameRuntime.php');
        $accept = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamSocketAcceptRuntime.php');
        $this->assertStringContainsString('#32807', $getName);
        $this->assertStringContainsString('#32807', $accept);
        $this->assertStringContainsString("getNamedFunction(self::ABI_NAME)", $getName);
        $this->assertStringContainsString("getNamedFunction(self::ABI_NAME)", $accept);
        $this->assertStringContainsString('__compiler_stream_socket_get_name', $getName);
        $this->assertStringContainsString('__compiler_stream_socket_accept', $accept);
        $this->assertStringContainsString('module->addFunction(', $getName);
        $this->assertStringContainsString('module->addFunction(', $accept);
    }

    public function testTypeInitializeStillEnsureLinksStreamSocketRuntimes(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamSocketGetNameRuntime::ensureLinked($this->context)', $type);
        $this->assertStringContainsString('StreamSocketAccept::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamSocketGetName.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamSocketAccept.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamSocketGetNameJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamSocketAcceptJitHelper.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_stream_socket.c'
        );
    }
}
