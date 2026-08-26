<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of CliArgvRuntime
 * (#35133 / peer ensureMinimal #34822 / #35127).
 *
 * Full standalone must not NestedJIT __phpc_cli_* during init (#32122 .1 mint class).
 * compileToFile ensures before main emits __phpc_cli_store_argv for every standalone.
 */
final class ContextFullStandaloneLazyCliArgvRuntimeShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerCliArgvNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35133', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            'CliArgvRuntime::ensureLinked($this)',
            'CliArgvRuntime::implement($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35133)'
            );
        }

        // Still links echo / refresh; StringFormat left ensureFull in #35130.
        $this->assertStringContainsString('ValueEchoRuntime::ensureLinked($this)', $fullBody);
        $this->assertStringContainsString('SuperglobalRefreshRuntime::ensureStandaloneBodies($this)', $fullBody);
        $this->assertStringNotContainsString(
            'StringFormat::ensureStandaloneBodies($this)',
            $fullBody,
            'StringFormat already lazy (#35130)'
        );
    }

    public function testCompileToFileEnsuresCliArgvForAllStandalone(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $compilePos = strpos($context, 'public function compileToFile(string $file)');
        $this->assertNotFalse($compilePos);
        $compileSlice = substr($context, $compilePos, 2500);
        $this->assertStringContainsString(
            'LOAD_TYPE_STANDALONE === $this->loadType',
            $compileSlice
        );
        $this->assertStringContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $compileSlice,
            'compileToFile must ensure CliArgv for every standalone (#35133)'
        );
        $this->assertStringContainsString('#35133', $compileSlice);
        // Thin-only gate must not wrap CliArgv ensure.
        $this->assertDoesNotMatchRegularExpression(
            '/LOAD_TYPE_STANDALONE === \$this->loadType && \$this->isThinStandaloneAotMain\(\)\s*\{\s*Builtin\\\\CliArgvRuntime::ensureStandaloneBodies/',
            $compileSlice,
            'CliArgv ensure must not be thin-only (#35133)'
        );
    }

    public function testBootstrapAotStillEnsuresCliArgv(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $bootPos = strpos($context, 'private function ensureBootstrapAotStandaloneBodies');
        $this->assertNotFalse($bootPos);
        $bootEnd = strpos($context, 'private function ensureFullStandaloneBodies', $bootPos);
        $this->assertNotFalse($bootEnd);
        $bootBody = substr($context, $bootPos, $bootEnd - $bootPos);
        $this->assertStringContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $bootBody,
            'bootstrap-aot fixtures still ensure CliArgv (#14459 / #35133)'
        );
    }

    public function testCallSitesStillEnsureBeforeLookup(): void
    {
        $init = (string) file_get_contents(__DIR__.'/../../lib/JIT/CliArgvGlobalInit.php');
        $this->assertStringContainsString(
            'CliArgvRuntime::ensureLinked($context)',
            $init,
            'CliArgvGlobalInit must ensure lazily (#35133)'
        );
        $getopt = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetopt.php');
        $this->assertStringContainsString('CliArgvRuntime::ensureLinked', $getopt);
    }

    public function testCliArgvRuntimeDocumentsLazyFull(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CliArgvRuntime.php');
        $this->assertStringContainsString('#35133', $source);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $source);
    }

    public function testNoNewRuntimeCForFullCliArgvLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/cli_argv.c',
            'must not add cli_argv.c for #35133 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_cli_argv.c',
            'must not re-add phpc_cli_argv.c for #35133'
        );
    }
}
