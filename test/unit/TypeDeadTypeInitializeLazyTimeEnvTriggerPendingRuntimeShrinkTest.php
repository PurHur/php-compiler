<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on StringTime / EnvLocal / TriggerError / PendingHeaders
 * ensureLinked (#34513 / peer #34474).
 *
 * Call sites link lazily so scripts that never touch those builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyTimeEnvTriggerPendingRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerTimeEnvTriggerPendingEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34513', $type);
        foreach ([
            'StringTime',
            'EnvLocalRuntime',
            'PendingHeadersRuntime',
        ] as $class) {
            $this->assertDoesNotMatchRegularExpression(
                '/'.'(?<![A-Za-z0-9_])'.$class.'::ensureLinked\\(\\$this->context\\)/',
                $type,
                "Builtin\\Type::initialize must not eagerly {$class}::ensureLinked (#34513)"
            );
        }
        // initialize() must not call StringTriggerError::ensureLinked — register() neither (#35392).
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($initPos);
        $initBody = substr($type, $initPos);
        $this->assertDoesNotMatchRegularExpression(
            '/StringTriggerError::ensureLinked\\(\\$this->context\\)/',
            $initBody,
            'Type::initialize must not eagerly StringTriggerError::ensureLinked (#34513)'
        );
        $regPos = strpos($type, 'public function register(): void');
        $this->assertNotFalse($regPos);
        $regBody = substr($type, $regPos, $initPos - $regPos);
        $this->assertStringNotContainsString(
            'StringTriggerError::ensureLinked($this->context)',
            $regBody,
            'Type::register must not eagerly StringTriggerError::ensureLinked (#35392)'
        );
        $ht = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString(
            'StringTriggerError::ensureLinked($this->context)',
            $ht,
            'HashTable::implement ensureLinked StringTriggerError for HELPER_RUNTIME_O=0 (#35392 / #33248)'
        );
        // SessionStorageGlobals::ensureGlobals also lazy as of #34566 (peer).
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'lib/JIT/Builtin/StringTime.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/TouchLibcRuntime.php' => 'StringTime::ensureLinked',
            'lib/JIT/Context.php' => 'EnvLocalRuntime::ensureBootstrapAotStubLinked',
            'lib/JIT/Builtin/EnvLocalRuntime.php' => 'JitEnvLocalKernel::ensureLinked',
            'ext/standard/trigger_error_.php' => 'StringTriggerError::ensureLinked',
            'ext/standard/JitBuiltinWarning.php' => 'StringTriggerError::ensureLinked',
            'ext/standard/header_.php' => 'PendingHeadersRuntime::ensureLinked',
            'lib/JIT/Builtin/PendingHeaders.php' => 'PendingHeadersRuntime::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34513)');
        }
    }

    public function testNoNewRuntimeCForLazyTimeEnvTriggerPendingAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'time.c',
            'phpc_time.c',
            'env_local.c',
            'phpc_env_local.c',
            'trigger_error.c',
            'phpc_trigger_error.c',
            'pending_header.c',
            'phpc_pending_header.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34513)');
        }
    }
}
