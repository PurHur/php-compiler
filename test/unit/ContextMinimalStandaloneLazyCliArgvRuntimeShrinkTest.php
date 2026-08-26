<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on CliArgvRuntime (#34822 / peer #34812).
 *
 * Thin AOT hello-world must not eagerly link CLI argv ABI during ensureMinimal —
 * compileToFile (all standalone, #35133) + CliArgvGlobalInit already ensureLinked (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyCliArgvRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerCliArgvRuntime(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34822', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly CliArgvRuntime (#34822)'
        );
        $this->assertStringNotContainsString(
            'CliArgvRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly CliArgvRuntime::ensureLinked (#34822)'
        );
    }

    public function testCompileToFileAndCallSitesStillEnsure(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $context,
            'compileToFile / bootstrap-aot still ensureStandaloneBodies (#34822 / #35133)'
        );

        $init = (string) file_get_contents(__DIR__.'/../../lib/JIT/CliArgvGlobalInit.php');
        $this->assertStringContainsString('CliArgvRuntime::ensureLinked($context)', $init);

        $getopt = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetopt.php');
        $this->assertStringContainsString('CliArgvRuntime::ensureLinked', $getopt);
    }

    public function testCliArgvRuntimeRestoresInsertBlock(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CliArgvRuntime.php');
        $this->assertStringContainsString('#34822', $source);
        $this->assertStringContainsString('ensureMinimalUserStandaloneBodies', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
    }

    public function testNoNewRuntimeCForMinimalCliArgvLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/cli_argv.c',
            'must not add cli_argv.c for #34822 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_cli_argv.c',
            'must not re-add phpc_cli_argv.c for #34822'
        );
    }
}
