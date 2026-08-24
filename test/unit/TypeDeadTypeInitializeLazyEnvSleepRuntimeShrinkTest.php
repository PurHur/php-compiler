<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on TimeSleep/getenv/microtime ensureLinked (#34320 / peer #34241).
 *
 * Call-site Jit* owners link lazily (getNamedFunction first) so hello-world and
 * other scripts that never touch these builtins skip NestedJIT on the full load
 * path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyEnvSleepRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerEnvSleepMicrotimeEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34320', $type);
        foreach ([
            'TimeSleepRuntime::ensureLinked($this->context)',
            'StringMicrotime::ensureLinked($this->context)',
            'StringGetenv::ensureLinked($this->context)',
            'StringGetenvAll::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34320)'
            );
        }
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitSleep.php' => 'TimeSleepRuntime::ensureLinked',
            'ext/standard/JitEnv.php' => 'StringGetenv::ensureLinked',
            'ext/standard/JitDate.php' => 'StringMicrotime::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensureLinked before lookup (#34320)');
        }
        $env = (string) file_get_contents(__DIR__.'/../../ext/standard/JitEnv.php');
        $this->assertStringContainsString('StringGetenvAll::ensureLinked', $env);
    }

    public function testNoNewRuntimeCForLazyEnvSleepMicrotimeAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach (['phpc_getenv.c', 'phpc_time_sleep.c', 'phpc_microtime.c'] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34320)');
        }
    }
}
