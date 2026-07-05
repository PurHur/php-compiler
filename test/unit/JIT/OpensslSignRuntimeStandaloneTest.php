<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\OpensslSignCrypto;
use PHPCompiler\JIT\Builtin\OpensslSignRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #16454: openssl_sign/verify LLVM ABI — lib/JIT/Builtin/runtime/openssl_ev.c (not lib/AOT/runtime/).
 *
 * @group aot-lint
 */
final class OpensslSignRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeEvCUnderJitBuiltinNotAotRuntime(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/openssl_ev.c');
        $this->assertFileExists(__DIR__.'/../../../lib/JIT/Builtin/runtime/openssl_ev.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString("'/runtime/openssl_ev.c'", $linker);
        $this->assertStringContainsString('OpensslSignRuntime::opensslEvRuntimeSources', $linker);
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

    public function testOpensslEvRuntimeSourcesEmptyWithoutHeaders(): void
    {
        if (OpensslSignRuntime::opensslEvRuntimeAvailable()) {
            $this->markTestSkipped('libssl-dev headers present');
        }
        $this->assertSame([], OpensslSignRuntime::opensslEvRuntimeSources());
    }
}
