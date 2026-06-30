<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Issue #6164 / #13857: DateTimeSupport must not delegate to host \\DateTime / \\DateTimeZone.
 */
final class DateTimeSupportRuntimeShrinkTest extends TestCase
{
    public function testDateTimeSupportRemovesHostZendDelegation(): void
    {
        $support = (string) file_get_contents(__DIR__.'/../../lib/VM/DateTimeSupport.php');
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDateTimeNative.php');
        $this->assertStringContainsString('VmDateTimeNative', $support);
        $this->assertStringNotContainsString('new \\DateTime', $support);
        $this->assertStringNotContainsString('new \\DateTimeZone', $support);
        $this->assertStringNotContainsString('\\DateTimeImmutable::createFromFormat', $support);
        $this->assertStringNotContainsString('toHost(', $support);
        $this->assertStringNotContainsString('syncFromHost', $support);
        $this->assertStringNotContainsString('new \\DateTime', $native);
        $this->assertStringNotContainsString('new \\DateTimeZone', $native);
        $this->assertStringNotContainsString('\\putenv(', $native);
        $this->assertDoesNotMatchRegularExpression('/(?<!VmEnv::)getenv\\([\'"]TZ[\'"]\\)/', $native);
        $this->assertStringContainsString('VmEnv::putenv', $native);
        $this->assertStringContainsString('VmEnv::getenv', $native);
        $this->assertStringContainsString('VmFs::file', $native);
        $this->assertStringContainsString('VmDatePure::', $native);
        $this->assertDoesNotMatchRegularExpression('/@\\\\file\\s*\\(/', $native);
        $this->assertStringNotContainsString('FFI::cdef', $native);
        $this->assertStringNotContainsString('libc.so', $native);
        $this->assertStringNotContainsString('private static function ffi(', $native);
    }
}
