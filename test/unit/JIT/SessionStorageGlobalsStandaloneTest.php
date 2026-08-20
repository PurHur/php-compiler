<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SessionEncodeRuntime;
use PHPCompiler\JIT\Builtin\SessionGcRuntime;
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
        SessionStorageGlobals::implementEnsureDefaults($ctx);
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

        $defaults = $ctx->lookupFunction('__phpc_session_ensure_defaults');
        $this->assertGreaterThan(0, $defaults->countBasicBlocks());
    }

    public function testEnsureLinkedIsIdempotentAndCallableFromJitInvoke(): void
    {
        $id = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitSessionId.php');
        $name = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitSessionName.php');
        $mod = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitSessionModuleName.php');
        $encode = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitSessionEncode.php');
        $decode = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitSessionDecode.php');
        $gc = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitSessionGc.php');
        $this->assertStringContainsString('Sid::ensureLinked', $id);
        $this->assertStringContainsString('Sname::ensureLinked', $name);
        $this->assertStringContainsString('Smod::ensureLinked', $mod);
        $this->assertStringContainsString('SessionEncodeRuntime::ensureLinked', $encode);
        $this->assertStringContainsString('SessionEncodeRuntime::ensureLinked', $decode);
        $this->assertStringContainsString('SessionGcRuntime::ensureLinked', $gc);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $encode);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $decode);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $gc);

        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        SessionId::ensureLinked($ctx);
        SessionId::ensureLinked($ctx);
        SessionName::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__phpc_session_id_apply');
        $this->assertGreaterThan(0, $fn->countBasicBlocks());

        SessionEncodeRuntime::ensureLinked($ctx);
        SessionEncodeRuntime::ensureLinked($ctx);
        SessionGcRuntime::ensureLinked($ctx);
        SessionGcRuntime::ensureLinked($ctx);
        foreach (['__phpc_session_encode_apply', '__phpc_session_decode_apply', '__phpc_session_gc_apply'] as $fnName) {
            $linked = $ctx->lookupFunction($fnName);
            $this->assertGreaterThan(0, $linked->countBasicBlocks(), $fnName);
        }
    }
}
