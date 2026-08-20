<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on openssl_digest ABI shell from Builtin\Type (#32868).
 *
 * NestedJIT/AOT bridge stays OpensslDigestRuntime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint openssl_digest.1 (#31894 / #32122).
 */
final class TypeDeadOpensslDigestAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_openssl_digest',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnOpensslDigestAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32868', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32868)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32868)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_fwrite'", $type);
        $this->assertStringContainsString('OpensslDigestRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresOpensslDigestAbiModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/OpensslDigestRuntime.php');
        $this->assertStringContainsString('#32868', $svc);
        $this->assertStringContainsString('declareAbi(', $svc);
        $this->assertStringContainsString('getNamedFunction($name)', $svc);
        $this->assertStringContainsString('module->addFunction(', $svc);
        $this->assertStringNotContainsString('lookupFunction($name)', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc);
        }
    }

    public function testTypeInitializeStillEnsureLinksOpensslDigestRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('OpensslDigestRuntime::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltin(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/openssl/OpensslDigestJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/openssl/JitOpensslDigest.php');
        $this->assertFileExists(__DIR__.'/../../ext/openssl/openssl_digest.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_digest.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_openssl_digest.c'
        );
    }
}
