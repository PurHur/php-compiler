<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of SuperglobalRefreshRuntime
 * (#35137 / peer #35133 CliArgv / #35127).
 *
 * Full standalone must not NestedJIT __superglobals__refresh during init (#32122 .1 mint class).
 * compileToFile ensures before main emits the call for every standalone.
 */
final class ContextFullStandaloneLazySuperglobalRefreshRuntimeShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerSuperglobalRefreshNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35137', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            'SuperglobalRefreshRuntime::ensureLinked($this)',
            'SuperglobalRefreshRuntime::ensureUserScriptRefreshEmit($this)',
            'SuperglobalRefreshRuntime::implement($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35137)'
            );
        }

        // Still links echo; CliArgv/#35133 + SuperglobalRefresh/#35137 at compileToFile.
        $this->assertStringContainsString('ValueEchoRuntime::ensureLinked($this)', $fullBody);
        $this->assertStringNotContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $fullBody,
            'CliArgv deferred to compileToFile (#35133)'
        );
    }

    public function testCompileToFileEnsuresSuperglobalRefreshForAllStandalone(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $compilePos = strpos($context, 'public function compileToFile(string $file)');
        $this->assertNotFalse($compilePos);
        $compileSlice = substr($context, $compilePos, 3500);
        $this->assertStringContainsString('#35137', $compileSlice);
        $this->assertStringContainsString(
            'SuperglobalRefreshRuntime::ensureUserScriptRefreshEmit($this)',
            $compileSlice,
            'thin path still ensureUserScriptRefreshEmit (#35137)'
        );
        $this->assertStringContainsString(
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            $compileSlice,
            'full path ensureStandaloneBodies at compileToFile (#35137)'
        );
        // Full ensure must live in the non-thin else branch (not thin-only).
        $thinEmitPos = strpos($compileSlice, 'SuperglobalRefreshRuntime::ensureUserScriptRefreshEmit($this)');
        $fullEnsurePos = strpos($compileSlice, 'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)');
        $this->assertNotFalse($thinEmitPos);
        $this->assertNotFalse($fullEnsurePos);
        $elsePos = strpos($compileSlice, '} else {', $thinEmitPos);
        $this->assertNotFalse($elsePos, 'thin/full if-else around SuperglobalRefresh (#35137)');
        $this->assertTrue(
            $fullEnsurePos > $elsePos && $elsePos > $thinEmitPos,
            'ensureStandaloneBodies must be in else of isThinStandaloneAotMain (#35137)'
        );
    }

    public function testBootstrapAotStillEnsuresSuperglobalRefresh(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $bootPos = strpos($context, 'private function ensureBootstrapAotStandaloneBodies');
        $this->assertNotFalse($bootPos);
        $bootEnd = strpos($context, 'private function ensureFullStandaloneBodies', $bootPos);
        $this->assertNotFalse($bootEnd);
        $bootBody = substr($context, $bootPos, $bootEnd - $bootPos);
        $this->assertStringContainsString(
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            $bootBody,
            'bootstrap-aot fixtures still ensure SuperglobalRefresh (#14459 / #35137)'
        );
    }

    public function testSuperglobalRefreshDocumentsLazyFull(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshRuntime.php');
        $this->assertStringContainsString('#35137', $source);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $source);
    }

    public function testNoNewRuntimeCForFullSuperglobalRefreshLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/superglobals_refresh.c',
            'must not add superglobals_refresh.c for #35137 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_superglobals_refresh.c',
            'must not add phpc_superglobals_refresh.c for #35137'
        );
    }
}
