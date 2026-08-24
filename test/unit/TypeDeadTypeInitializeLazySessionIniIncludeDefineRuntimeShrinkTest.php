<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on Ini/IncludePath/WeakRef/Session/Define/RewriteVars
 * ensureLinked (#34474 / peer #34463).
 *
 * Call sites link lazily so scripts that never touch those builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazySessionIniIncludeDefineRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerSessionIniIncludeDefineEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34474', $type);
        foreach ([
            'WeakRefRegistryRuntime',
            'IniRuntime',
            'IncludePathRuntime',
            'SessionLifecycleRuntime',
            'SessionCreateIdRuntime',
            'SessionGcRuntime',
            'SessionStorageRuntime',
            'SessionEncodeRuntime',
            'DefineRuntime',
            'RewriteVarsRuntime',
        ] as $class) {
            $this->assertDoesNotMatchRegularExpression(
                '/'.'(?<![A-Za-z0-9_])'.$class.'::ensureLinked\\(\\$this->context\\)/',
                $type,
                "Builtin\\Type::initialize must not eagerly {$class}::ensureLinked (#34474)"
            );
        }
        $this->assertStringContainsString(
            'StringTime::ensureLinked($this->context)',
            $type,
            'StringTime stays eager (#34474 / TimeRuntimeShrinkTest)'
        );
        $this->assertStringContainsString(
            'EnvLocalRuntime::ensureLinked($this->context)',
            $type,
            'EnvLocalRuntime stays eager (#34474 / TypeDeadEnvLocalAbiRuntimeShrinkTest)'
        );
        $this->assertStringContainsString(
            'SessionStorageGlobals::ensureGlobals($this->context)',
            $type,
            'SessionStorageGlobals::ensureGlobals stays (#34474)'
        );
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitIni.php' => 'IniRuntime::ensureLinked',
            'lib/JIT/Builtin/IniGet.php' => 'IniRuntime::ensureLinked',
            'lib/JIT/Builtin/IniSet.php' => 'IniRuntime::ensureLinked',
            'ext/standard/JitIncludePath.php' => 'IncludePathRuntime::ensureLinked',
            'ext/standard/JitResolveIncludePath.php' => 'IncludePathRuntime::ensureLinked',
            'ext/standard/JitFile.php' => 'IncludePathRuntime::ensureLinked',
            'lib/JIT/Builtin/WeakRefRuntime.php' => 'WeakRefRegistryRuntime::ensureLinked',
            'ext/standard/JitSessionStart.php' => 'SessionLifecycleRuntime::ensureLinked',
            'ext/standard/JitSessionCreateId.php' => 'SessionCreateIdRuntime::ensureLinked',
            'ext/standard/JitSessionGc.php' => 'SessionGcRuntime::ensureLinked',
            'ext/standard/JitSessionLifecycleKernel.php' => 'SessionStorageRuntime::ensureLinked',
            'ext/standard/JitSessionEncode.php' => 'SessionEncodeRuntime::ensureLinked',
            'ext/standard/JitSessionDecode.php' => 'SessionEncodeRuntime::ensureLinked',
            'ext/standard/JitDefine.php' => 'DefineRuntime::ensureLinked',
            'lib/JIT/Builtin/DefineRuntime.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/RewriteVarsRuntime.php' => 'self::ensureLinked($context)',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34474)');
        }
    }

    public function testNoNewRuntimeCForLazySessionIniIncludeDefineAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'ini_get.c',
            'include_path.c',
            'phpc_include_path.c',
            'session_start.c',
            'phpc_session_lifecycle.c',
            'define.c',
            'output_rewrite_vars.c',
            'weakref_registry.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34474)');
        }
    }
}
