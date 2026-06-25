<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\PendingHeadersRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5344 / #6340: AOT standalone must define pending header helpers without phpc_pending_headers.c.
 *
 * @group aot-lint
 */
final class PendingHeadersRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesPendingHeadersC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_pending_headers.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_pending_headers.c', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/PendingHeadersRuntime.php');
        $this->assertStringContainsString('PendingHeadersStandaloneLlvm', $runtime);
        $standalone = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/PendingHeadersStandaloneLlvm.php');
        $this->assertStringContainsString('appendSetcookieExpires', $standalone);
        $this->assertStringContainsString('gmtime', $standalone);
    }

    public function testEnsureLinkedDefinesPendingHeadersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        PendingHeadersRuntime::ensureLinked($ctx);

        foreach (
            [
                '__phpc_pending_header_reset',
                '__phpc_header_queue_enable',
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
