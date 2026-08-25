<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on SessionStorageGlobals::ensureGlobals (#34566 / peer #34550).
 *
 * Call sites ensureGlobals lazily so scripts that never touch session_* skip the
 * LLVM session module globals on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazySessionStorageGlobalsRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerSessionStorageGlobalsEnsureGlobals(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34566', $type);
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($initPos);
        $initBody = substr($type, $initPos);
        $this->assertDoesNotMatchRegularExpression(
            '/SessionStorageGlobals::ensureGlobals\\(\\$this->context\\)/',
            $initBody,
            'Type::initialize must not eagerly SessionStorageGlobals::ensureGlobals (#34566)'
        );
        $this->assertStringContainsString(
            'SessionStorageGlobals::ensureGlobals always-on removed (#34566)',
            $initBody,
            'document lazy SessionStorageGlobals (#34566)'
        );
    }

    public function testCallSitesEnsureGlobalsBeforeUse(): void
    {
        $checks = [
            'ext/standard/JitSessionStorageKernel.php' => 'SessionStorageGlobals::ensureGlobals',
            'ext/standard/JitSessionLifecycleKernel.php' => 'SessionStorageGlobals::ensureGlobals',
            'ext/standard/JitSessionStatus.php' => 'SessionStorageGlobals::ensureGlobals',
            'ext/standard/JitSessionCacheExpire.php' => 'SessionStorageGlobals::ensureGlobals',
            'lib/JIT/Builtin/SessionId.php' => 'SessionStorageGlobals::ensureGlobals',
            'lib/JIT/Builtin/SessionName.php' => 'SessionStorageGlobals::ensureGlobals',
            'lib/JIT/Builtin/SessionModuleName.php' => 'SessionStorageGlobals::ensureGlobals',
            'lib/JIT/Builtin/SessionGcRuntime.php' => 'SessionStorageGlobals::ensureGlobals',
            'lib/JIT/Builtin/SessionEncodeRuntime.php' => 'SessionStorageGlobals::ensureGlobals',
            'lib/JIT/Builtin/SessionCreateIdRuntime.php' => 'SessionStorageGlobals::ensureGlobals',
            'lib/JIT/Builtin/SessionStorageGlobals.php' => 'public static function ensureGlobals',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensureGlobals lazily (#34566)');
        }
        $globals = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionStorageGlobals.php');
        $this->assertStringContainsString(
            'self::ensureGlobals($context)',
            $globals,
            'emitCallEnsureDefaults/implementEnsureDefaults must call ensureGlobals (#34566)'
        );
    }

    public function testNoNewRuntimeCForLazySessionStorageGlobals(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'session_storage.c',
            'phpc_session_storage.c',
            'session_id_storage.c',
            'session_name_storage.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                "must not add {$name} for #34566 — PHP SessionStorageGlobals only"
            );
        }
    }
}
