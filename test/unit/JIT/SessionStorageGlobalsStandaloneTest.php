<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SessionId;
use PHPCompiler\JIT\Builtin\SessionName;
use PHPCompiler\JIT\Builtin\SessionStorageGlobals;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5694: AOT standalone must link session id/name buffers without dedicated storage .c TUs.
 *
 * @group aot-lint
 */
final class SessionStorageGlobalsStandaloneTest extends TestCase
{
    public function testStandaloneDeclaresSessionGlobalsAndApply(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        SessionStorageGlobals::ensureGlobals($ctx);
        SessionId::implement($ctx);
        SessionName::implement($ctx);

        foreach (
            [
                SessionStorageGlobals::GLOBAL_ID_BUF,
                SessionStorageGlobals::GLOBAL_ID_LEN,
                SessionStorageGlobals::GLOBAL_NAME_BUF,
                SessionStorageGlobals::GLOBAL_NAME_LEN,
                SessionStorageGlobals::GLOBAL_ACTIVE,
            ] as $globalName
        ) {
            $this->assertNotNull($ctx->module->getNamedGlobal($globalName), $globalName);
        }

        foreach (['__phpc_session_id_apply', '__phpc_session_name_apply'] as $fnName) {
            $fn = $ctx->lookupFunction($fnName);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }

        $this->assertNull($ctx->module->getNamedFunction('__phpc_session_ensure_defaults'));
    }
}
