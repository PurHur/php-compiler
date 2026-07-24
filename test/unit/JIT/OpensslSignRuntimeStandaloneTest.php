<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\OpensslSignCrypto;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #3324 / #16454: openssl_sign/verify LLVM ABI via OpensslSignJitHelper PHP.
 *
 * @group aot-lint
 */
final class OpensslSignRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesOpensslEvC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/openssl_ev.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/runtime/openssl_ev.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('openssl_ev.c', $linker);
        $this->assertStringContainsString('OPENSSL_LINK_LIB', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/OpensslSignRuntime.php');
        $this->assertStringContainsString('OpensslSignJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
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
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
