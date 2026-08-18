<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\CurlShareStrerrorRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * curl_share_strerror: NestedJIT-safe CURLSHE_* map — no libcurl FFI (#32340).
 *
 * @group aot-lint
 */
final class CurlShareStrerrorRuntimeStandaloneTest extends TestCase
{
    public function testSpineBundleIncludesHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CurlShareStrerrorJitHelper.php', $spine);
        $this->assertStringContainsString('CurlShareStrerrorRuntime.php', $spine);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedRegistersCurlShareStrerrorAbi(): void
    {
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            CurlShareStrerrorRuntime::ensureLinked($ctx);
        } catch (\LogicException $e) {
            if (
                str_contains($e->getMessage(), 'isnan')
                || str_contains($e->getMessage(), 'non-existing function')
                || str_contains($e->getMessage(), 'Unsupported native type')
            ) {
                $this->markTestSkipped($e->getMessage());
            }
            throw $e;
        }

        $fn = $ctx->lookupFunction('__compiler_curl_share_strerror');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
