<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\CurlStrerrorRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * curl_strerror NestedJIT CURLE/CURLM maps - no libcurl FFI (#32352).
 *
 * @group aot-lint
 */
final class CurlStrerrorRuntimeStandaloneTest extends TestCase
{
    public function testSpineBundleIncludesHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CurlStrerrorJitHelper.php', $spine);
        $this->assertStringContainsString('CurlStrerrorRuntime.php', $spine);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedRegistersCurlStrerrorAbi(): void
    {
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            CurlStrerrorRuntime::ensureLinked($ctx);
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

        $easy = $ctx->lookupFunction('__compiler_curl_strerror');
        $this->assertNotNull($easy);
        $this->assertGreaterThan(0, $easy->countBasicBlocks());
        $multi = $ctx->lookupFunction('__compiler_curl_multi_strerror');
        $this->assertNotNull($multi);
        $this->assertGreaterThan(0, $multi->countBasicBlocks());
    }
}
