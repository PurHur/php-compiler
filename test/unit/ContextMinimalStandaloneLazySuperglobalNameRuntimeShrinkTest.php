<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on SuperglobalNameRuntime (#34812 / peer #34807).
 *
 * Thin AOT hello-world must not NestedJIT __compiler_is_superglobal_name —
 * JitSuperglobalName / JIT.php already ensureLinked (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazySuperglobalNameRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerSuperglobalNameRuntime(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34812', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'SuperglobalNameRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly SuperglobalNameRuntime (#34812)'
        );

        // CLI argv still NestedJIT before {main} $argc/$argv lowering.
        $this->assertStringContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $minimalBody,
            'keep CliArgvRuntime in minimal (#34812)'
        );
    }

    public function testCallSitesEnsureBeforeLookup(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSuperglobalName.php');
        $this->assertStringContainsString('StringSuperglobalName::ensureLinked($context)', $jit);

        $jitPhp = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString(
            'StringSuperglobalName::ensureLinked($this->context)',
            $jitPhp,
            'compileSuperglobalNameNative must ensureLinked before lookup (#34812)'
        );
    }

    public function testSuperglobalNameRuntimeDocumentsLazyMinimal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalNameRuntime.php');
        $this->assertStringContainsString('#34812', $source);
        $this->assertStringContainsString('ensureMinimalUserStandaloneBodies', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
    }

    public function testNoNewRuntimeCForMinimalSuperglobalNameLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/superglobal_name.c',
            'must not add superglobal_name.c for #34812 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/is_superglobal_name.c',
            'must not re-add is_superglobal_name.c for #34812'
        );
    }
}
