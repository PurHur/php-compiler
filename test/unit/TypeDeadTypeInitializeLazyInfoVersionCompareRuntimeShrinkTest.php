<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on StringInfo / StringVersionCompare ensureLinked
 * (#34337 / peer #34333).
 *
 * Call-site JitInfo / ReflectionExtensionGetVersion link lazily (getNamedFunction
 * first) so hello-world and other scripts that never touch info/version builtins
 * skip NestedJIT on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyInfoVersionCompareRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerStringInfoVersionCompareEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34337', $type);
        foreach ([
            'StringInfo::ensureLinked($this->context)',
            'StringVersionCompare::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34337)'
            );
        }
        // StringTime lazy as of #34513 — see TypeDeadTypeInitializeLazyTimeEnvTriggerPendingRuntimeShrinkTest.
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitInfo.php' => 'StringInfo::ensureLinked',
            'lib/JIT/Call/ReflectionExtensionGetVersion.php' => 'StringInfo::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34337)');
        }
        $jitInfo = (string) file_get_contents(__DIR__.'/../../ext/standard/JitInfo.php');
        $this->assertStringContainsString('StringVersionCompare::ensureLinked', $jitInfo);
    }

    public function testNoNewRuntimeCForLazyInfoVersionCompareAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'phpc_info.c',
            'phpc_version_compare.c',
            'phpc_phpversion.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34337)');
        }
    }
}
