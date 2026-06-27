<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringPasswordCrypto;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6906 / #9908: password LLVM helpers replace password_crypto.c via PasswordJitHelper PHP.
 *
 * @group aot-lint
 */
final class StringPasswordCryptoRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesPasswordCryptoC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/password_crypto.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringPasswordCryptoJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringPasswordCryptoStandaloneLlvm.php');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('password_crypto.c', $linker);
        $wrapper = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringPasswordCrypto.php');
        $this->assertStringContainsString('PasswordCryptoRuntime', $wrapper);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedDefinesPasswordHashForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringPasswordCrypto::ensureLinked($ctx);

        foreach ([
            '__compiler_password_hash',
            '__compiler_password_verify',
            '__compiler_crypt',
            '__compiler_password_get_info',
            '__compiler_password_needs_rehash',
            '__compiler_password_algos',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
