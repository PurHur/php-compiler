<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SyslogJitHelper;
use PHPCompiler\ext\standard\VmSyslog;
use PHPUnit\Framework\TestCase;

/** StringSyslog routes through SyslogJitHelper PHP not libc LLVM (#9254). */
final class StringSyslogRuntimeShrinkTest extends TestCase
{
    public function testStringSyslogRoutesThroughSyslogJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSyslog.php');
        $this->assertStringContainsString('SyslogJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('openlog')", $source);
        $this->assertStringNotContainsString("lookupFunction('syslog')", $source);
        $this->assertStringNotContainsString("lookupFunction('closelog')", $source);
        $this->assertStringNotContainsString('phpc_syslog_opened', $source);
        $this->assertLessThan(220, \substr_count($source, "\n") + 1);
    }

    public function testJitSyslogPassesStringPointersNotCStrings(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSyslog.php');
        $this->assertStringNotContainsString('ownedCString', $source);
        $this->assertStringContainsString('__compiler_syslog_openlog', $source);
    }

    public function testSyslogJitHelperDelegatesToVmSyslog(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SyslogJitHelper.php');
        $this->assertStringContainsString('VmSyslog::openlog', $source);
        $this->assertStringContainsString('VmSyslog::syslog', $source);
        $this->assertStringContainsString('VmSyslog::closelog', $source);
    }

    public function testSyslogJitHelperSemanticsMatchVmSyslogWhenAvailable(): void
    {
        if (!VmSyslog::available()) {
            self::markTestSkipped('libc syslog unavailable (FFI disabled or missing)');
        }

        SyslogJitHelper::openlog('phpc-test', 0, 8);
        $ok = SyslogJitHelper::write(6, 'helper probe');
        SyslogJitHelper::closelog();
        self::assertTrue($ok);
    }
}
