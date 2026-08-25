<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::compileToFile always-on IniRuntime for thin standalone (#34848 / peer #34822 / #34578).
 *
 * Thin AOT hello-world must not NestedJIT ini ABI before {main}; call sites ensureLinked lazily
 * (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyIniCompileToFileRuntimeShrinkTest extends TestCase
{
    public function testCompileToFileThinDropsEagerIniRuntime(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34848', $context);
        $pos = strpos($context, 'public function compileToFile');
        $this->assertNotFalse($pos);
        $next = strpos($context, 'public function ', $pos + 20);
        $body = false === $next
            ? substr($context, $pos)
            : substr($context, $pos, $next - $pos);

        $this->assertStringContainsString('isThinStandaloneAotMain()', $body);
        $this->assertStringNotContainsString(
            'IniRuntime::ensureLinked($this)',
            $body,
            'compileToFile must not eagerly IniRuntime for thin standalone (#34848)'
        );
        $this->assertStringContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $body,
            'compileToFile still ensures CliArgv for thin standalone (#34822)'
        );
        $this->assertStringContainsString(
            'SuperglobalRefreshRuntime::ensureUserScriptRefreshEmit($this)',
            $body,
            'compileToFile still emits superglobal refresh for thin standalone'
        );
    }

    public function testCallSitesEnsureBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitIni.php' => 'IniRuntime::ensureLinked',
            'lib/JIT/Builtin/IniGet.php' => 'IniRuntime::ensureLinked',
            'lib/JIT/Builtin/IniSet.php' => 'IniRuntime::ensureLinked',
            'lib/JIT/Builtin/ErrorReporting.php' => 'IniRuntime::ensureLinked',
            'lib/JIT/Builtin/ZendDoubleStringRuntime.php' => 'IniRuntime::ensureLinked',
            'lib/JIT/Builtin/ExceptionThrowToStringSeed.php' => 'IniRuntime::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#34848)');
        }
    }

    public function testNoNewRuntimeCForMinimalIniLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/ini_runtime.c',
            'must not add ini_runtime.c for #34848 — PHP JIT bridges only'
        );
    }
}
