<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on StringTriggerError (#34641 / peer #34631).
 *
 * Thin AOT hello-world must not eagerly NestedJIT trigger_error ABI; call sites
 * ensureLinked lazily (#32122 .1 mint class / #33234 Type drop).
 */
final class ContextMinimalStandaloneLazyStringTriggerErrorShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerStringTriggerError(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34641', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'StringTriggerError::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly StringTriggerError (#34641)'
        );

        // CliArgv always-on removed (#34822): compileToFile + CliArgvGlobalInit ensureLinked.
        $this->assertStringNotContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly CliArgvRuntime (#34822)'
        );
        $this->assertStringNotContainsString(
            'ErrorBridge::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly ErrorBridge (#34769)'
        );
        $this->assertStringNotContainsString(
            'ExceptionBridge::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly ExceptionBridge (#34732)'
        );
        $this->assertStringNotContainsString(
            'ObOutputRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimal must not eagerly ObOutputRuntime (#34695)'
        );
        $this->assertStringNotContainsString(
            'StringHtmlspecialchars::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly StringHtmlspecialchars (#34642)'
        );

        // Full standalone also drops StringTriggerError (#35073); AssertFail::ensureLinked covers #33234.
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);
        $this->assertStringNotContainsString(
            'StringTriggerError::ensureStandaloneBodies($this)',
            $fullBody,
            'ensureFullStandaloneBodies must not eagerly StringTriggerError (#35073)'
        );
        $assert = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertFail.php');
        $this->assertStringContainsString('StringTriggerError::ensureLinked($context)', $assert);
    }

    public function testCallSitesEnsureBeforeLookup(): void
    {
        $checks = [
            'ext/standard/trigger_error_.php' => 'StringTriggerError::ensureLinked',
            'ext/standard/JitBuiltinWarning.php' => 'StringTriggerError::ensureLinked',
            'lib/JIT/JitIncDec.php' => 'StringTriggerError::ensureLinked',
            'lib/JIT/HashTableResourceKeyLlvm.php' => 'StringTriggerError::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#34641)');
        }
    }

    public function testJitTriggerErrorKernelRestoresInsert(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTriggerErrorKernel.php');
        $this->assertStringContainsString('#34641', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringNotContainsString(
            "\$context->builder->clearInsertionPosition();\n    }",
            $source,
            'implement() must not always clear insert (#34641)'
        );
    }

    public function testNoNewRuntimeCForMinimalTriggerErrorLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/trigger_error.c',
            'must not add trigger_error.c for #34641 — PHP JIT bridges only'
        );
    }
}
