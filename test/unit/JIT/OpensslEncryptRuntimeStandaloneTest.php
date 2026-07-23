<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\OpensslEncryptCrypto;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #21065: openssl_encrypt/decrypt LLVM ABI via OpensslEncryptJitHelper PHP.
 *
 * @group aot-lint
 */
final class OpensslEncryptRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeUsesPhpHelperNotC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/openssl_cipher.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/runtime/openssl_cipher.c');
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/OpensslEncryptRuntime.php');
        $this->assertStringContainsString('OpensslEncryptJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedRegistersOpensslEncryptDecryptAbi(): void
    {
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            OpensslEncryptCrypto::ensureLinked($ctx);
        } catch (\LogicException $e) {
            // Peer OpensslSignRuntimeStandaloneTest: STANDALONE Context may hit
            // missing libc math decls (isnan) during StringFormat NestedJIT on some hosts.
            if (str_contains($e->getMessage(), 'isnan') || str_contains($e->getMessage(), 'non-existing function')) {
                $this->markTestSkipped($e->getMessage());
            }
            throw $e;
        }

        foreach ([
            '__compiler_openssl_encrypt',
            '__compiler_openssl_decrypt',
            '__compiler_openssl_encrypt_take_tag',
            '__compiler_openssl_encrypt_tag_is_null',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
