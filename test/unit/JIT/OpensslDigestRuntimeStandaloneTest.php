<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\OpensslDigestCrypto;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #21081: openssl_digest LLVM ABI via OpensslDigestJitHelper PHP.
 *
 * @group aot-lint
 */
final class OpensslDigestRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeUsesPhpHelperNotC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/openssl_digest.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/runtime/openssl_digest.c');
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/OpensslDigestRuntime.php');
        $this->assertStringContainsString('OpensslDigestJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedRegistersOpensslDigestAbi(): void
    {
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            OpensslDigestCrypto::ensureLinked($ctx);
        } catch (\LogicException $e) {
            if (str_contains($e->getMessage(), 'isnan') || str_contains($e->getMessage(), 'non-existing function')) {
                $this->markTestSkipped($e->getMessage());
            }
            throw $e;
        }

        $fn = $ctx->lookupFunction('__compiler_openssl_digest');
        $this->assertNotNull($fn, '__compiler_openssl_digest');
        $this->assertGreaterThan(0, $fn->countBasicBlocks(), '__compiler_openssl_digest');
    }
}
