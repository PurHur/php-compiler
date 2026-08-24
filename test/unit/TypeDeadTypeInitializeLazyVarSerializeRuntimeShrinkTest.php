<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on StringVarExport / StringPrintR / StringVarDump /
 * StringSerialize / StringUnserialize ensureLinked (#34384 / peer #34357).
 *
 * Call-site JitVarExport / JitPrintR / JitVarDump / JitSerialize / JitUnserialize
 * link lazily (getNamedFunction first) so scripts that never touch var.c builtins
 * skip NestedJIT on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyVarSerializeRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerVarSerializeEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34384', $type);
        foreach ([
            'StringVarExport::ensureLinked($this->context)',
            'StringPrintR::ensureLinked($this->context)',
            'StringVarDump::ensureLinked($this->context)',
            'StringSerialize::ensureLinked($this->context)',
            'StringUnserialize::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34384)'
            );
        }
        // StringTime lazy as of #34513 — see TypeDeadTypeInitializeLazyTimeEnvTriggerPendingRuntimeShrinkTest.
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitVarExport.php' => 'StringVarExport::ensureLinked',
            'ext/standard/JitPrintR.php' => 'StringPrintR::ensureLinked',
            'ext/standard/JitVarDump.php' => 'StringVarDump::ensureLinkedAtCallSite',
            'ext/standard/JitSerialize.php' => 'StringSerialize::ensureLinked',
            'ext/standard/JitUnserialize.php' => 'StringUnserialize::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34384)');
        }
    }

    public function testNoNewRuntimeCForLazyVarSerializeAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'phpc_var_export.c',
            'phpc_print_r.c',
            'phpc_var_dump.c',
            'phpc_serialize.c',
            'phpc_unserialize.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34384)');
        }
    }
}
