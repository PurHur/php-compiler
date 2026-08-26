<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of SuperglobalRefreshRuntime
 * (#35137 / peer CliArgv #35133 / ensureMinimal thin refresh).
 *
 * Full standalone must not NestedJIT __superglobals__refresh during init (#32122 .1 mint class).
 * compileToFile ensures before main emits __superglobals__refresh for every standalone.
 * php-src: main/php_variables.c
 */
final class ContextFullStandaloneLazySuperglobalRefreshShrinkTest extends TestCase
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

        // Still links echo; CliArgv + SuperglobalRefresh deferred to compileToFile.
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
        $compileSlice = substr($context, $compilePos, 2800);
        $this->assertStringContainsString(
            'LOAD_TYPE_STANDALONE === $this->loadType',
            $compileSlice
        );
        $this->assertStringContainsString(
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            $compileSlice,
            'compileToFile must ensure SuperglobalRefresh for every standalone (#35137)'
        );
        $this->assertStringContainsString('#35137', $compileSlice);
        // Thin-only gate must not wrap SuperglobalRefresh ensure.
        $this->assertDoesNotMatchRegularExpression(
            '/isThinStandaloneAotMain\(\)\s*\{\s*Builtin\\\\SuperglobalRefreshRuntime::ensure/',
            $compileSlice,
            'SuperglobalRefresh ensure must not be thin-only (#35137)'
        );
        $this->assertStringNotContainsString(
            'SuperglobalRefreshRuntime::ensureUserScriptRefreshEmit($this)',
            $compileSlice,
            'unified on ensureStandaloneBodies for all standalone (#35137)'
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

    public function testJitEnsureLinkedBeforeResolve(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString(
            'SuperglobalRefreshRuntime::ensureLinked($this->context)',
            $jit,
            'JIT.php must ensure lazily before resolve (#35137)'
        );
    }

    public function testSuperglobalRefreshRuntimeDocumentsLazyFull(): void
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
            'must not re-add superglobals_refresh.c for #35137 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_superglobals_refresh.c',
            'must not add phpc_superglobals_refresh.c for #35137'
        );
    }
}
