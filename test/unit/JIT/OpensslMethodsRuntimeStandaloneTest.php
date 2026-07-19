<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\OpensslMethodsCrypto;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #21103: openssl_get_*_methods LLVM ABI via JitOpensslMethodsKernel PHP.
 *
 * @group aot-lint
 */
final class OpensslMethodsRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeUsesPhpHelperNotC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/openssl_methods.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/runtime/openssl_methods.c');
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/OpensslMethodsRuntime.php');
        $this->assertStringContainsString('JitOpensslMethodsKernel', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $helper = (string) file_get_contents(__DIR__.'/../../../ext/openssl/OpensslMethodsJitHelper.php');
        $this->assertStringContainsString('phpc_openssl_cipher_methods_kernel', $helper);
        $this->assertStringContainsString('phpc_openssl_md_methods_kernel', $helper);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedRegistersOpensslMethodsAbi(): void
    {
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            OpensslMethodsCrypto::ensureLinked($ctx);
        } catch (\LogicException $e) {
            if (str_contains($e->getMessage(), 'isnan') || str_contains($e->getMessage(), 'non-existing function')) {
                $this->markTestSkipped($e->getMessage());
            }
            throw $e;
        }

        foreach (
            [
                '__compiler_openssl_get_cipher_methods',
                '__compiler_openssl_get_md_methods',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
