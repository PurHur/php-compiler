<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * date_add/date_sub/date_modify/date_diff JIT routes through DateMutationJitHelper PHP
 * via JitVmHelperLink::ensureCompiled (#8770 / #26750 / peer #26699).
 */
final class DateMutationRuntimeShrinkTest extends TestCase
{
    public function testDateMutationJitHelperDelegatesToVmDateTimeNative(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/DateMutationJitHelper.php');
        $this->assertStringContainsString('VmDateTimeNative', $source);
    }

    public function testDateMutationRuntimeRoutesThroughDateMutationJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DateMutationRuntime.php');
        $this->assertStringContainsString('DateMutationJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(340, \substr_count($source, "\n"), 'DateMutationRuntime must drop hand-rolled NestedJIT glue');
    }

    public function testSpineBundleIncludesDateMutationJitHelper(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('DateMutationJitHelper.php', $spine);
        $this->assertStringContainsString('DateMutationRuntime.php', $spine);
    }
}
