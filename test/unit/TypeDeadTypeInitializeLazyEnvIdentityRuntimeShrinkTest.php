<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on ProcessIdentity/gettimeofday/getrusage/net/ListUnpack
 * ensureLinked (#34327 / peer #34320).
 *
 * Call-site Jit* / ListUnpack owners link lazily (getNamedFunction first) so
 * hello-world and other scripts that never touch these builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyEnvIdentityRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerProcessIdentityGettimeofdayNetListUnpackEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34327', $type);
        foreach ([
            'ProcessIdentityJit::ensureLinked($this->context)',
            'StringGettimeofday::ensureLinked($this->context)',
            'StringGetrusage::ensureLinked($this->context)',
            'StringNetInterfacesJit::ensureLinked($this->context)',
            'ListUnpackRuntime::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34327)'
            );
        }
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'lib/JIT/Builtin/ProcessIdentityJit.php' => 'self::ensureLinked($context)',
            'ext/standard/JitGettimeofday.php' => 'StringGettimeofday::ensureLinked',
            'ext/standard/JitGetrusage.php' => 'StringGetrusage::ensureLinked',
            'ext/standard/JitNetGetInterfaces.php' => 'StringNetInterfacesJit::ensureLinked',
            'lib/JIT/ListUnpackHelper.php' => 'ListUnpackRuntime::ensureLinked',
            'lib/JIT/Builtin/CallUnpackRuntime.php' => 'ListUnpackRuntime::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensureLinked before lookup (#34327)');
        }
    }

    public function testNoNewRuntimeCForLazyEnvIdentityAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'phpc_process_identity.c',
            'phpc_gettimeofday.c',
            'phpc_getrusage.c',
            'phpc_net_interfaces.c',
            'phpc_list_unpack.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34327)');
        }
    }
}
