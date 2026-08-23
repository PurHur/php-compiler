<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on SessionId/Name/ModuleName::implement (#33980 / peer #33965).
 *
 * NestedJIT/AOT bridges stay SessionId / SessionName / SessionModuleName ensureLinked
 * (php-src ext/session/session.c). Call-site ensureLinked owns the ABI so leftover
 * Type always-on NestedJIT cannot mint session_id_apply.1 (#31894 / #32122).
 * No-op SessionStart/WriteClose/…::implement stubs (#21564) are also dropped.
 */
final class TypeDeadSessionIdNameModuleAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerSessionIdNameModuleImplement(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33980', $type);
        foreach ([
            'SessionId::implement($this->context)',
            'SessionName::implement($this->context)',
            'SessionModuleName::implement($this->context)',
            'SessionStart::implement($this->context)',
            'SessionWriteClose::implement($this->context)',
            'SessionRegenerateId::implement($this->context)',
            'SessionDestroy::implement($this->context)',
            'SessionAbort::implement($this->context)',
            'SessionUnset::implement($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#33980)'
            );
        }
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__phpc_session_id_apply[\'"]/',
            $type,
            'Builtin\\Type must not always-declare session_id ABI (#33980)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__phpc_session_name_apply[\'"]/',
            $type,
            'Builtin\\Type must not always-declare session_name ABI (#33980)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__phpc_session_module_apply[\'"]/',
            $type,
            'Builtin\\Type must not always-declare session_module ABI (#33980)'
        );
        $this->assertStringContainsString('SessionLifecycleRuntime::ensureLinked', $type);
        $this->assertStringContainsString('SessionStorageRuntime::ensureLinked', $type);
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        foreach ([
            'JitSessionId.php' => "lookupFunction('__phpc_session_id_apply')",
            'JitSessionName.php' => "lookupFunction('__phpc_session_name_apply')",
            'JitSessionModuleName.php' => "lookupFunction('__phpc_session_module_apply')",
        ] as $file => $lookup) {
            $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/'.$file);
            $this->assertStringContainsString('#32989', $jit);
            $this->assertStringContainsString('ensureLinked($context)', $jit);
            $posLink = strpos($jit, 'ensureLinked($context)');
            $posLookup = strpos($jit, $lookup);
            $this->assertNotFalse($posLink, $file.' must ensureLinked');
            $this->assertNotFalse($posLookup, $file.' must lookup apply ABI');
            $this->assertLessThan(
                $posLookup,
                $posLink,
                $file.' must ensureLinked before lookup (#33980/#32989)'
            );
        }
    }

    public function testOwnersGetNamedFunctionFirst(): void
    {
        foreach (['SessionId.php', 'SessionName.php', 'SessionModuleName.php'] as $file) {
            $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/'.$file);
            $this->assertStringContainsString('function ensureLinked(Context $context): void', $owner);
            $this->assertStringContainsString('getNamedFunction', $owner);
            $this->assertStringContainsString('countBasicBlocks()', $owner);
        }
    }

    public function testNoNewRuntimeCForSessionIdNameModuleAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/session_id.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/session_id.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_session_id.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/session_name.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/session_module_name.c');
    }
}
