<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on LastErrorRuntime (#34631 / peer #34621).
 *
 * Thin AOT hello-world must not eagerly NestedJIT last-error ABI; JitErrorGetLast /
 * JitTriggerErrorKernel ensureLinked lazily (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyLastErrorRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerLastError(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34631', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'LastErrorRuntime::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly LastErrorRuntime (#34631)'
        );

        // Essentials for thin echo / error / argv / getenv surface stay.
        foreach ([
            'StringHtmlspecialchars::ensureStandaloneBodies($this)',
            'ObOutputRuntime::ensureLinked($this)',
            'StringTriggerError::ensureStandaloneBodies($this)',
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            'EnvLocalRuntime::ensureLinked($this)',
            'SuperglobalNameRuntime::ensureLinked($this)',
            'ExceptionBridge::ensureStandaloneBodies($this)',
            'ErrorBridge::ensureStandaloneBodies($this)',
        ] as $keep) {
            $this->assertStringContainsString($keep, $minimalBody, "keep {$keep} in minimal (#34631)");
        }

        // Full standalone still links LastError after TriggerError.
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullBody = substr($context, $fullPos);
        $this->assertStringContainsString('LastErrorRuntime::ensureStandaloneBodies($this)', $fullBody);
    }

    public function testCallSitesEnsureBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitErrorGetLast.php' => 'LastErrorRuntime::ensureLinked',
            'ext/standard/JitTriggerErrorKernel.php' => 'LastErrorRuntime::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#34631)');
        }
    }

    public function testLastErrorRuntimeRestoresInsert(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LastErrorRuntime.php');
        $this->assertStringContainsString('#34631', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringNotContainsString(
            "\$context->builder->clearInsertionPosition();\n    }",
            $source,
            'implement() must not always clear insert (#34631)'
        );
    }

    public function testNoNewRuntimeCForMinimalLastErrorLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/last_error.c',
            'must not add last_error.c for #34631 — PHP JIT bridges only'
        );
    }
}
