<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on CallArgv::implement (#34550 / peer #34534).
 *
 * Call sites ensureGlobal lazily so scripts that never touch func_get_args /
 * func_num_args skip the module global on the full load path.
 */
final class TypeDeadTypeInitializeLazyCallArgvRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerCallArgvImplement(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34550', $type);
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($initPos);
        $initBody = substr($type, $initPos);
        $this->assertDoesNotMatchRegularExpression(
            '/CallArgv::implement\\(\\$this->context\\)/',
            $initBody,
            'Type::initialize must not eagerly CallArgv::implement (#34550)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/CallArgv::ensureGlobal\\(\\$this->context\\)/',
            $initBody,
            'Type::initialize must not eagerly CallArgv::ensureGlobal (#34550)'
        );
        // SessionStorageGlobals::ensureGlobals also lazy as of #34566 (peer).
    }

    public function testCallSitesEnsureGlobalBeforeUse(): void
    {
        $checks = [
            'lib/JIT/Call/Native.php' => 'CallArgv::emitStore',
            'ext/standard/JitFuncArgs.php' => 'CallArgv::load',
            'lib/JIT/Builtin/CallArgv.php' => 'public static function ensureGlobal',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must use CallArgv lazily (#34550)');
        }
        $callArgv = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CallArgv.php');
        $this->assertStringContainsString(
            'self::ensureGlobal($context)',
            $callArgv,
            'emitStore/load must call ensureGlobal (#34550)'
        );
    }

    public function testNoNewRuntimeCForLazyCallArgv(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'call_argv.c',
            'phpc_call_argv.c',
            'func_get_args.c',
            'func_num_args.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                "must not add {$name} for #34550 — PHP CallArgv only"
            );
        }
    }
}
