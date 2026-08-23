<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on PowInt/network/date/inet ensureLinked (#34243 / peer #33980).
 *
 * Call-site Jit* / ext/standard owners link lazily (getNamedFunction first) so
 * hello-world and other scripts that never touch these builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyNetworkMathRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerPowIntNetworkDateInetEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34243', $type);
        foreach ([
            'PowIntRuntime::ensureLinked($this->context)',
            'GethostbynamelRuntime::ensureLinked($this->context)',
            'GethostbyaddrRuntime::ensureLinked($this->context)',
            'CheckdnsrrRuntime::ensureLinked($this->context)',
            'CheckdateRuntime::ensureLinked($this->context)',
            'DateIntervalFormatRuntime::ensureLinked($this->context)',
            'StringDateTime::ensureLinked($this->context)',
            'StringDeployPath::ensureLinked($this->context)',
            'StringStrftime::ensureLinked($this->context)',
            'StringStrptime::ensureLinked($this->context)',
            'DefaultTimezoneRuntime::ensureLinked($this->context)',
            'DefaultTimezoneCivilRuntime::ensureLinked($this->context)',
            'InetRuntime::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34243)'
            );
        }
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitPow.php' => 'PowIntRuntime::ensureLinked',
            'ext/standard/JitGethostbynamel.php' => 'GethostbynamelRuntime::ensureLinked',
            'ext/standard/JitGethostbyaddr.php' => 'GethostbyaddrRuntime::ensureLinked',
            'ext/standard/JitCheckdnsrr.php' => 'CheckdnsrrRuntime::ensureLinked',
            'ext/standard/JitCheckdate.php' => 'CheckdateRuntime::ensureLinked',
            'ext/standard/JitDateIntervalFormat.php' => 'DateIntervalFormatRuntime::ensureLinked',
            'ext/standard/JitDate.php' => 'StringDateTime::ensureLinked',
            'ext/standard/JitDeployPath.php' => 'StringDeployPath::ensureLinked',
            'ext/standard/JitStrptime.php' => 'StringStrptime::ensureLinked',
            'ext/standard/JitInet.php' => 'InetRuntime::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensureLinked before lookup (#34243)');
        }
    }

    public function testNoNewRuntimeCForLazyNetworkMathAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach (['phpc_pow.c', 'network.c', 'inet.c'] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34243)');
        }
    }
}
