<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on session ABI shells from Builtin\Type (#33261).
 *
 * NestedJIT/AOT bridges stay SessionLifecycleRuntime / JitSessionLifecycleKernel /
 * SessionCreateIdRuntime / SessionGcRuntime / SessionEncodeRuntime
 * (php-src ext/session/session.c). Runtime owners declare module-locally
 * (getNamedFunction first) so leftover Type empty decls cannot mint session_*.1
 * (#31894 / #32122).
 */
final class TypeDeadSessionAbiRuntimeShrinkTest extends TestCase
{
    /** @var list<string> */
    private const SESSION_ABIS = [
        '__phpc_session_start_apply',
        '__phpc_session_write_close_apply',
        '__phpc_session_generate_new_id',
        '__phpc_session_regenerate_id_apply',
        '__phpc_session_destroy_apply',
        '__phpc_session_abort_apply',
        '__phpc_session_reset_apply',
        '__phpc_session_unset_apply',
        '__phpc_session_create_id_apply',
        '__phpc_session_create_id_apply_boxed',
        'phpc_session_random_id_string',
        '__phpc_session_gc_apply',
        'phpc_session_gc_expired_files',
        '__phpc_session_encode_apply',
        '__phpc_session_decode_apply',
        'phpc_session_encode_wire',
        'phpc_session_decode_wire',
    ];

    public function testTypeBuiltinDropsLeftoverAlwaysOnSessionAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33261', $type);
        foreach (self::SESSION_ABIS as $abi) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($abi, '/').'[\'"]/',
                $type,
                'Builtin\\Type must not always-declare '.$abi.' (#33261)'
            );
            $this->assertStringNotContainsString(
                "registerFunction('".$abi."'",
                $type,
                'Builtin\\Type must not always-register '.$abi.' (#33261)'
            );
        }
        // No further Type always-on leftover after exit/abort drop (#33267).
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]exit[\'"]/',
            $type,
            'Builtin\\Type must not always-declare exit (#33267)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]abort[\'"]/',
            $type,
            'Builtin\\Type must not always-declare abort (#33267)'
        );
        $this->assertStringContainsString('SessionLifecycleRuntime::declareSessionAbis', $type);
        $this->assertStringContainsString('SessionLifecycleRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresSessionAbiModuleLocally(): void
    {
        $orch = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionLifecycleRuntime.php');
        $this->assertStringContainsString('#33261', $orch);
        $this->assertStringContainsString('declareSessionAbis', $orch);
        $this->assertStringContainsString('JitSessionLifecycleKernel::declareSessionLifecycleAbis', $orch);
        $this->assertStringContainsString('SessionCreateIdRuntime::declareSessionCreateIdAbis', $orch);
        $this->assertStringContainsString('SessionGcRuntime::declareSessionGcAbis', $orch);
        $this->assertStringContainsString('SessionEncodeRuntime::declareSessionEncodeAbis', $orch);

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSessionLifecycleKernel.php');
        $this->assertStringContainsString('#33261', $kernel);
        $this->assertStringContainsString('declareSessionLifecycleAbis', $kernel);
        $this->assertStringContainsString('getNamedFunction', $kernel);
        $this->assertStringContainsString('__phpc_session_start_apply', $kernel);

        $createId = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionCreateIdRuntime.php');
        $this->assertStringContainsString('#33261', $createId);
        $this->assertStringContainsString('declareSessionCreateIdAbis', $createId);
        $this->assertStringContainsString('getNamedFunction', $createId);

        $gc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionGcRuntime.php');
        $this->assertStringContainsString('#33261', $gc);
        $this->assertStringContainsString('declareSessionGcAbis', $gc);

        $encode = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionEncodeRuntime.php');
        $this->assertStringContainsString('#33261', $encode);
        $this->assertStringContainsString('declareSessionEncodeAbis', $encode);
    }

    public function testNoNewRuntimeCForSessionAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_session_lifecycle.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/phpc_session_lifecycle.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/session_start.c');
    }
}
