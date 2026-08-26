<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of ValueEchoRuntime
 * (#35143 / peer #35137 SuperglobalRefresh / #35133 CliArgv).
 *
 * Full standalone must not NestedJIT value_echo_* during init (#32122 .1 mint class).
 * emitValue / dump peers ensureLinked before type-bridge use.
 */
final class ContextFullStandaloneLazyValueEchoRuntimeShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerValueEchoNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35143', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'ValueEchoRuntime::ensureStandaloneBodies($this)',
            'ValueEchoRuntime::ensureLinked($this)',
            'ValueEchoRuntime::implement($this)',
            'ValueEchoRuntime::emitValue($this',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35143)'
            );
        }

        // CliArgv/#35133 + SuperglobalRefresh/#35137 at compileToFile; ObOutput stays lazy.
        $this->assertStringNotContainsString(
            'ObOutputRuntime::ensureLinked($this)',
            $fullBody,
            'ObOutput remains lazy (#34695)'
        );
        $this->assertStringNotContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $fullBody,
            'CliArgv deferred to compileToFile (#35133)'
        );
        $this->assertStringNotContainsString(
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            $fullBody,
            'SuperglobalRefresh deferred to compileToFile (#35137)'
        );
    }

    public function testEmitValueAndDumpPeersEnsureBeforeLookup(): void
    {
        $echo = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueEchoRuntime.php');
        $this->assertStringContainsString('#35143', $echo);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $echo);
        $emitPos = strpos($echo, 'public static function emitValue');
        $this->assertNotFalse($emitPos);
        $emitNext = strpos($echo, 'public static function ', $emitPos + 10);
        $emitBody = false === $emitNext
            ? substr($echo, $emitPos)
            : substr($echo, $emitPos, $emitNext - $emitPos);
        $this->assertStringContainsString(
            'self::ensureLinked($context)',
            $emitBody,
            'emitValue must ensureLinked before type bridges (#35143)'
        );

        $checks = [
            'lib/JIT/Builtin/StringVarDump.php' => 'ValueEchoRuntime::ensureLinked',
            'lib/JIT/Builtin/StringPrintR.php' => 'ValueEchoRuntime::ensureLinked',
            'lib/JIT/Builtin/StringVarExport.php' => 'ValueEchoRuntime::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#35143)');
        }
    }

    public function testNoNewRuntimeCForFullValueEchoLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/value_echo.c',
            'must not add value_echo.c for #35143 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_value_echo.c',
            'must not add phpc_value_echo.c for #35143'
        );
    }
}
