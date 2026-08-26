<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of StringTriggerError /
 * AssertFail / AssertOptions / JitReturnPending / LastError / ProgressNote / Gc*
 * (#35073 / peers #34605 / #34621 / #34631 / #34641 / #35065).
 *
 * Full standalone must not NestedJIT assert_fail* / trigger_error / return_pending /
 * last_error / progress_note / gc_* during init (#32122 .1 mint class). Call sites
 * already ensureLinked; AssertFail::ensureLinked implements standalone bodies.
 */
final class ContextFullStandaloneLazyAssertGcProgressTriggerShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerAssertGcProgressTriggerNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35073', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'StringTriggerError::ensureStandaloneBodies($this)',
            'AssertFail::ensureStandaloneBodies($this)',
            'AssertOptionsRuntime::ensureStandaloneBodies($this)',
            'JitReturnPending::ensureStandaloneBodies($this)',
            'LastErrorRuntime::ensureStandaloneBodies($this)',
            'ProgressNoteRuntime::ensureStandaloneBodies($this)',
            'GcToggleRuntime::ensureStandaloneBodies($this)',
            'GcCollectCyclesRuntime::ensureStandaloneBodies($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35073)'
            );
        }

        // Still links echo; CliArgv + SuperglobalRefresh deferred (#35133 / #35137).
        $this->assertStringContainsString('ValueEchoRuntime::ensureLinked($this)', $fullBody);
        $this->assertStringNotContainsString(
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            $fullBody,
            'SuperglobalRefresh deferred to compileToFile (#35137)'
        );
        // compileToFile ensureLinked fills return-pending before clear.
        $this->assertStringContainsString('JitReturnPending::ensureLinked($this)', $context);
    }

    public function testCallSitesStillEnsureBeforeLookup(): void
    {
        $assert = (string) file_get_contents(__DIR__.'/../../ext/standard/JitAssert.php');
        $this->assertStringContainsString('AssertFail::ensureLinked($context)', $assert);

        $opts = (string) file_get_contents(__DIR__.'/../../ext/standard/JitAssertOptions.php');
        $this->assertStringContainsString('AssertOptionsRuntime::ensureLinked($context)', $opts);

        $try = (string) file_get_contents(__DIR__.'/../../lib/JIT/TryCatchHelper.php');
        $this->assertStringContainsString('JitReturnPending::ensureLinked($context)', $try);

        $last = (string) file_get_contents(__DIR__.'/../../ext/standard/JitErrorGetLast.php');
        $this->assertStringContainsString('LastErrorRuntime::ensureLinked($context)', $last);

        $gc = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGcCollectCycles.php');
        $this->assertStringContainsString('GcCollectCyclesRuntime::ensureLinked($context)', $gc);

        $toggle = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGcToggle.php');
        $this->assertStringContainsString('GcToggleRuntime::ensureLinked($context)', $toggle);

        $progress = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('ProgressNoteRuntime::ensureLinked($this->context)', $progress);
    }

    public function testAssertFailEnsureLinkedImplementsStandaloneBodies(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertFail.php');
        $this->assertStringContainsString('#35073', $source);
        $this->assertStringContainsString('StringTriggerError::ensureLinked($context)', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        // Must not leave empty standalone decls for later ensureStandaloneBodies.
        $this->assertStringNotContainsString(
            'declare empty ABI so later ensureStandaloneBodies',
            $source,
            'AssertFail::ensureLinked must implement standalone bodies (#35073)'
        );
    }

    public function testNoNewRuntimeCForFullStandaloneLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'assert_fail.c',
            'progress_note.c',
            'gc_collect_cycles.c',
            'return_pending.c',
            'trigger_error.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                "must not add {$name} for #35073 — PHP JIT bridges only"
            );
        }
    }
}
