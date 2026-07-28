<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ErrorLogJitHelper;
use PHPCompiler\ext\standard\VmErrorLog;
use PHPUnit\Framework\TestCase;

/**
 * StringErrorLog NestedJIT via JitVmHelperLink::ensureCompiled (#24094 / peer #22519).
 */
final class StringErrorLogRuntimeShrinkTest extends TestCase
{
    public function testStringErrorLogRoutesThroughErrorLogJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringErrorLog.php');
        $this->assertStringContainsString('ErrorLogJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString("lookupFunction('fprintf')", $source);
        $this->assertStringNotContainsString('stringToCString', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(100, \substr_count($source, "\n") + 1);
    }

    public function testErrorLogJitHelperDelegatesToVmErrorLog(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ErrorLogJitHelper.php');
        $this->assertStringContainsString('VmErrorLog::errorLog', $source);
    }

    public function testErrorLogJitHelperSemanticsMatchVmErrorLog(): void
    {
        $this->assertSame(
            VmErrorLog::errorLog(0, 'probe'),
            ErrorLogJitHelper::logStderr('probe')
        );
    }
}
