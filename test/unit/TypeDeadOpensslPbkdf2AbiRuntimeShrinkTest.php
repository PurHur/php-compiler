<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on openssl_pbkdf2 ABI shell from Builtin\Type (#32870).
 *
 * NestedJIT/AOT bridge stays OpensslPbkdf2Runtime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint openssl_pbkdf2.1 (#31894 / #32122).
 */
final class TypeDeadOpensslPbkdf2AbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_openssl_pbkdf2',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnOpensslPbkdf2Abi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32870', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32870)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32870)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_convert_uuencode'", $type);
        $this->assertStringContainsString('OpensslPbkdf2Runtime', $type);
    }

    public function testRuntimeOwnerDeclaresOpensslPbkdf2AbiModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/OpensslPbkdf2Runtime.php');
        $this->assertStringContainsString('#32870', $svc);
        $this->assertStringContainsString('declareAbi(', $svc);
        $this->assertStringContainsString('getNamedFunction($name)', $svc);
        $this->assertStringContainsString('module->addFunction(', $svc);
        $this->assertStringNotContainsString('lookupFunction($name)', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc);
        }
    }

    public function testUserScriptBuiltinStillEnsureLinksOpensslPbkdf2Runtime(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/openssl/openssl_pbkdf2.php');
        $this->assertStringContainsString('OpensslPbkdf2Runtime::ensureLinked', $src);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltin(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/openssl/openssl_pbkdf2.php');
        $this->assertFileExists(__DIR__.'/../../ext/openssl/JitOpensslPbkdf2.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_pbkdf2.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/runtime/openssl_pbkdf2.c'
        );
    }
}
