<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\SensitiveParamJitHelper;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPCompiler\ext\standard\VmDebugBacktrace;
use PHPUnit\Framework\TestCase;

/** SensitiveParam JIT routes ignore-args + redaction SSOT through SensitiveParamJitHelper PHP (#10394). */
final class SensitiveParamRuntimeShrinkTest extends TestCase
{
    public function testSensitiveParamHelperDelegatesToSensitiveParamRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/SensitiveParamHelper.php');
        $this->assertStringContainsString('SensitiveParamRuntime::createMarker', $source);
        $this->assertStringContainsString('SensitiveParamRuntime::ignoreArgsBit', $source);
        $this->assertStringNotContainsString('VmDebugBacktraceOptions', $source);
        $this->assertStringNotContainsString('readOptionsLong', $source);
        $this->assertLessThan(60, substr_count($source, "\n") + 1);
    }

    public function testSensitiveParamRuntimeUsesSensitiveParamJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SensitiveParamRuntime.php');
        $this->assertStringContainsString('SensitiveParamJitHelper', $source);
        $this->assertStringContainsString('shouldIgnoreBacktraceArgs', $source);
        $this->assertStringContainsString('__sensitive_param__shouldIgnoreBacktraceArgs', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
    }

    public function testSensitiveParamJitHelperIgnoreArgsParity(): void
    {
        $mask = VmDebugBacktrace::IGNORE_ARGS;
        $this->assertTrue(SensitiveParamJitHelper::shouldIgnoreBacktraceArgs($mask));
        $this->assertFalse(SensitiveParamJitHelper::shouldIgnoreBacktraceArgs(0));
        $this->assertSame($mask, SensitiveParamJitHelper::ignoreArgsOptionMask());
        $this->assertSame($mask, SensitiveParamSupport::BACKTRACE_IGNORE_ARGS);
    }

    public function testCompileTimeParamIsSensitiveShared(): void
    {
        $sensitive = [1 => true];
        $this->assertTrue(SensitiveParamSupport::compileTimeParamIsSensitive($sensitive, 1));
        $this->assertFalse(SensitiveParamSupport::compileTimeParamIsSensitive($sensitive, 0));
        $this->assertTrue(SensitiveParamJitHelper::compileTimeParamIsSensitive($sensitive, 1));
        $this->assertSame(
            SensitiveParamSupport::TRACE_ARG_LABEL,
            SensitiveParamJitHelper::traceArgLabel()
        );
        $this->assertSame(
            SensitiveParamSupport::CLASS_NAME,
            SensitiveParamJitHelper::markerClassName()
        );
    }
}
