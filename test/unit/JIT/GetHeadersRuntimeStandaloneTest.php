<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\GetHeadersRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9212: get_headers must link via GetHeadersJitHelper PHP bridge.
 *
 * @group aot-lint
 */
final class GetHeadersRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGetHeadersBridgeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        GetHeadersRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__compiler_get_headers');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
