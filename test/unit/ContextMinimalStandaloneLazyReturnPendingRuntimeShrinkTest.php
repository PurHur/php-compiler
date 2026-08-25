<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on JitReturnPending (#34621 / peer #34612).
 *
 * Thin AOT hello-world must not NestedJIT return-through-finally ABI; TryCatchHelper /
 * emitPendingReturnResume ensureLinked lazily (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyReturnPendingRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerJitReturnPending(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34621', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'JitReturnPending::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly JitReturnPending (#34621)'
        );

        // Essentials for thin argv / getenv / bridges stay (#34695 dropped ObOutput;
        // #34641 dropped StringTriggerError).
        // LastError dropped in #34631 (peer this test).
        foreach ([
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            'EnvLocalRuntime::ensureLinked($this)',
            'SuperglobalNameRuntime::ensureLinked($this)',
            'ExceptionBridge::ensureStandaloneBodies($this)',
            'ErrorBridge::ensureStandaloneBodies($this)',
        ] as $keep) {
            $this->assertStringContainsString($keep, $minimalBody, "keep {$keep} in minimal (#34621)");
        }
        $this->assertStringNotContainsString(
            'ObOutputRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimal must not eagerly ObOutputRuntime (#34695)'
        );
        $this->assertStringNotContainsString(
            'StringTriggerError::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly StringTriggerError (#34641)'
        );
        $this->assertStringNotContainsString(
            'LastErrorRuntime::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly LastErrorRuntime (#34631)'
        );

        // Full standalone still links return-pending after TriggerError.
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullBody = substr($context, $fullPos);
        $this->assertStringContainsString('JitReturnPending::ensureStandaloneBodies($this)', $fullBody);
    }

    public function testCallSitesEnsureBeforeLookup(): void
    {
        $checks = [
            'lib/JIT/TryCatchHelper.php' => 'JitReturnPending::ensureLinked',
            'lib/JIT.php' => 'JitReturnPending::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#34621)');
        }
    }

    public function testJitHelperAbiBridgeRestoresInsert(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JitHelperAbiBridge.php');
        $this->assertStringContainsString('#34621', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringNotContainsString(
            "\$context->builder->clearInsertionPosition();\n    }",
            $source,
            'implement() must not always clear insert (#34621)'
        );
    }

    public function testNoNewRuntimeCForMinimalReturnPendingLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/return_pending.c',
            'must not add return_pending.c for #34621 — PHP JIT bridges only'
        );
    }
}
