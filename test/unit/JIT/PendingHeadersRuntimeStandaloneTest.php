<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\PendingHeadersRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5344: AOT standalone must define pending header helpers without phpc_pending_headers.c.
 *
 * @group aot-lint
 */
final class PendingHeadersRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesPendingHeadersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        PendingHeadersRuntime::ensureLinked($ctx);

        foreach (
            [
                '__phpc_pending_header_reset',
                '__phpc_pending_header_add',
                '__phpc_pending_header_remove',
                '__phpc_pending_header_list',
                '__phpc_response_headers_flush',
                '__phpc_setcookie_add',
                '__phpc_headers_sent',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
