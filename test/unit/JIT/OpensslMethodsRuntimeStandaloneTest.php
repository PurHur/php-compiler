<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\openssl\OpensslCipherRegistry;
use PHPCompiler\ext\openssl\OpensslMethodsJitHelper;
use PHPCompiler\JIT\Builtin\OpensslMethodsCrypto;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * openssl_get_*_methods: NestedJIT-safe OpensslCipherRegistry lists — no registry kernel (#21103, #30148).
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
        $this->assertStringContainsString('OpensslMethodsJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringNotContainsString('JitOpensslMethodsKernel', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('phpc_openssl_cipher_methods_kernel', $runtime);
        $this->assertStringNotContainsString('__hashtable__setStringAt', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../../ext/openssl/OpensslMethodsJitHelper.php');
        $this->assertStringContainsString('OpensslCipherRegistry::CIPHER_METHODS', $helper);
        $this->assertStringContainsString('OpensslCipherRegistry::MD_METHODS', $helper);
        $this->assertStringNotContainsString('phpc_openssl_cipher_methods_kernel', $helper);
        $this->assertStringNotContainsString('phpc_openssl_md_methods_kernel', $helper);
        $this->assertFileDoesNotExist(__DIR__.'/../../../ext/openssl/phpc_openssl_cipher_methods_kernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../../ext/openssl/phpc_openssl_md_methods_kernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../../ext/openssl/JitOpensslMethodsKernel.php');

        $this->assertSame(OpensslCipherRegistry::CIPHER_METHODS, OpensslMethodsJitHelper::cipherMethodsArgv(0));
        $this->assertSame(OpensslCipherRegistry::MD_METHODS, OpensslMethodsJitHelper::mdMethodsArgv(0));
    }

    public function testContextNoLongerAllowlistsOpensslMethodsKernels(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_openssl_cipher_methods_kernel', $source);
        $this->assertStringNotContainsString('phpc_openssl_md_methods_kernel', $source);
    }

    public function testSpineBundleIncludesHelperOmitsKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('OpensslMethodsJitHelper.php', $spine);
        $this->assertStringContainsString('OpensslMethodsRuntime.php', $spine);
        $this->assertStringNotContainsString('JitOpensslMethodsKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_openssl_cipher_methods_kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_openssl_md_methods_kernel.php', $spine);
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
