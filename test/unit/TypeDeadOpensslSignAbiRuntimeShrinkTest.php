<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on openssl_sign/verify ABI shells from Builtin\Type (#32866).
 *
 * NestedJIT/AOT bridge stays OpensslSignRuntime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint openssl_sign.1 (#31894 / #32122).
 */
final class TypeDeadOpensslSignAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_openssl_sign',
            '__compiler_openssl_verify',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnOpensslSignAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32866', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32866)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32866)"
            );
        }
        // No further Type always-on leftover after exit/abort drop (#33267).
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]exit[\'"]/',
            $type,
            'Builtin\\Type must not always-declare exit (#33267)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]abort[\'"]/',
            $type,
            'Builtin\\Type must not always-declare abort (#33267)'
        );
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('OpensslSignRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresOpensslSignAbisModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/OpensslSignRuntime.php');
        $this->assertStringContainsString('#32866', $svc);
        $this->assertStringContainsString('declareAbi(', $svc);
        $this->assertStringContainsString('getNamedFunction($name)', $svc);
        $this->assertStringContainsString('module->addFunction(', $svc);
        $this->assertStringNotContainsString('lookupFunction($name)', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc);
        }
    }

    public function testTypeInitializeStillEnsureLinksOpensslSignRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('OpensslSignRuntime::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/openssl/OpensslSignJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/openssl/JitOpensslSign.php');
        $this->assertFileExists(__DIR__.'/../../ext/openssl/openssl_sign.php');
        $this->assertFileExists(__DIR__.'/../../ext/openssl/openssl_verify.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_ev.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_openssl_sign.c'
        );
    }
}
