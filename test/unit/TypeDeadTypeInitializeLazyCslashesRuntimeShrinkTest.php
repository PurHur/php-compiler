<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on StringCslashes::ensureStandaloneBodies (#34534 / peer #34513).
 *
 * Call sites link lazily so scripts that never touch addcslashes/stripcslashes skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyCslashesRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerCslashesEnsureStandaloneBodies(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34534', $type);
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($initPos);
        $initBody = substr($type, $initPos);
        $this->assertDoesNotMatchRegularExpression(
            '/StringCslashes::ensureStandaloneBodies\\(\\$this->context\\)/',
            $initBody,
            'Type::initialize must not eagerly StringCslashes::ensureStandaloneBodies (#34534)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/StringCslashes::ensureLinked\\(\\$this->context\\)/',
            $initBody,
            'Type::initialize must not eagerly StringCslashes::ensureLinked (#34534)'
        );
        // SessionStorageGlobals::ensureGlobals also lazy as of #34566 (peer).
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/addcslashes.php' => 'StringCslashes::ensureLinked',
            'ext/standard/stripcslashes.php' => 'StringCslashes::ensureStripcslashes',
            'lib/JIT/Builtin/StringStripcslashesRuntime.php' => 'StringCslashes::ensureStripcslashes',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34534)');
        }
    }

    public function testNoNewRuntimeCForLazyCslashesAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'cslashes.c',
            'phpc_cslashes.c',
            'addcslashes.c',
            'stripcslashes.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                "must not add {$name} for #34534 — PHP NestedJIT only"
            );
        }
    }
}
