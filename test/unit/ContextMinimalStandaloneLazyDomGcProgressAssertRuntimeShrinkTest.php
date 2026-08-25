<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on Dom/ProgressNote/Gc/AssertFail (#34605 / peer #34578).
 *
 * Thin AOT hello-world must not NestedJIT those ABIs; call sites ensureLinked / ensureBridge lazily
 * (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyDomGcProgressAssertRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerDomGcProgressAssert(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34605', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        foreach ([
            'DomStandaloneAotInitRuntime::ensureLinked($this)',
            'DomInstanceMethodRuntime::ensureLinked($this)',
            'ProgressNoteRuntime::ensureStandaloneBodies($this)',
            'GcCollectCyclesRuntime::ensureStandaloneBodies($this)',
            'AssertFail::ensureStandaloneBodies($this)',
        ] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $minimalBody,
                "ensureMinimalUserStandaloneBodies must not eagerly {$needle} (#34605)"
            );
        }

        // CLI argv still NestedJIT before {main} (#34812 dropped SuperglobalName).
        foreach ([
            'CliArgvRuntime::ensureStandaloneBodies($this)',
        ] as $keep) {
            $this->assertStringContainsString($keep, $minimalBody, "keep {$keep} in minimal (#34605)");
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
        foreach ([
            'HtmlEntitiesJit::ensureStandaloneBodies($this)',
            'StringHtmlspecialcharsDecode::ensureStandaloneBodies($this)',
            'ErrorHandlerJitRuntime::ensureStandaloneBodies($this)',
            'ExceptionHandlerJitRuntime::ensureStandaloneBodies($this)',
        ] as $dropped) {
            $this->assertStringNotContainsString(
                $dropped,
                $minimalBody,
                "ensureMinimal must not eagerly {$dropped} (#34612)"
            );
        }

        // Full standalone still links AssertFail / ProgressNote / Gc after TriggerError (#33234).
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullBody = substr($context, $fullPos);
        $this->assertStringContainsString('AssertFail::ensureStandaloneBodies($this)', $fullBody);
        $this->assertStringContainsString('ProgressNoteRuntime::ensureStandaloneBodies($this)', $fullBody);
        $this->assertStringContainsString('GcCollectCyclesRuntime::ensureStandaloneBodies($this)', $fullBody);
    }

    public function testCallSitesEnsureBeforeLookup(): void
    {
        $checks = [
            'lib/JIT/VmActiveContextInitLlvm.php' => 'DomStandaloneAotInitRuntime::ensureLinked',
            'lib/JIT/Builtin/DomInstanceMethodRuntime.php' => 'self::ensureBridge',
            'lib/JIT.php' => 'ProgressNoteRuntime::ensureLinked',
            'ext/standard/JitGcCollectCycles.php' => 'GcCollectCyclesRuntime::ensureLinked',
            'ext/standard/JitAssert.php' => 'AssertFail::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#34605)');
        }

        // Mid-{main} ensureLinked must restore builder insert (#34605 / Module.php:180).
        $gc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('captureInsertBlock($context)', $gc);
        $this->assertStringContainsString('restoreInsertBlock($context, $restoreBlock)', $gc);
        $this->assertStringContainsString('#34605', $gc);
    }

    public function testNoNewRuntimeCForMinimalDomGcProgressAssertLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'dom_standalone_aot_init.c',
            'progress_note.c',
            'gc_collect_cycles.c',
            'assert_fail.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                "must not add {$name} for #34605 — PHP JIT bridges only"
            );
        }
    }
}
