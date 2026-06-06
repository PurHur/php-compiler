<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SessionStorageRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6968: AOT standalone must define session file I/O without phpc_session_storage.c.
 *
 * @group aot-lint
 */
final class SessionStorageRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesSessionStorageForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        SessionStorageRuntime::ensureLinked($ctx);

        foreach (
            [
                'phpc_session_load_from_disk',
                'phpc_session_save_to_disk',
                'phpc_session_unlink_file',
                'phpc_session_apply_incoming_cookie',
                'phpc_session_emit_setcookie',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
