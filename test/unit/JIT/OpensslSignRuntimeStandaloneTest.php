<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\OpensslSignCrypto;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #3324: openssl_sign/verify LLVM ABI + lib/AOT/runtime/openssl_ev.c.
 *
 * @group aot-lint
 */
final class OpensslSignRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeUsesThinLibcryptoC(): void
    {
        $this->assertFileExists(__DIR__.'/../../../lib/AOT/runtime/openssl_ev.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringContainsString('openssl_ev.c', $linker);
        $this->assertStringContainsString('-lcrypto', $linker);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedRegistersOpensslSignVerifyAbi(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        OpensslSignCrypto::ensureLinked($ctx);

        foreach ([
            '__compiler_openssl_sign',
            '__compiler_openssl_verify',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
        }
    }
}
