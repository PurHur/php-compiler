<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixSessionJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\ext\posix\VmPosixSessionPure;
use PHPUnit\Framework\TestCase;

/** posix_getsid()/posix_getpgid() embed JIT routes through PosixSessionJitHelper not libc FFI (#12685). */
final class PosixSessionRuntimeShrinkTest extends TestCase
{
    public function testPosixSessionRuntimeUsesJitHelperNotLibcLookup(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixSessionRuntime.php');
        $this->assertStringContainsString('PosixSessionJitHelper', $runtime);
        $this->assertStringContainsString('getsidStandalone', $runtime);
        $this->assertStringContainsString('getpgidStandalone', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $jitPosix = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixSessionRuntime::getsid', $jitPosix);
        $this->assertStringContainsString('PosixSessionRuntime::getpgid', $jitPosix);
        $this->assertMatchesRegularExpression(
            '/public static function getsid\(Context \$context, JITVariable \$pidArg\): Value\s*\{\s*return PosixSessionRuntime::getsid/',
            $jitPosix
        );
        $this->assertMatchesRegularExpression(
            '/public static function getpgid\(Context \$context, JITVariable \$pidArg\): Value\s*\{\s*return PosixSessionRuntime::getpgid/',
            $jitPosix
        );

        $helper = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixSessionJitHelper.php');
        $this->assertStringContainsString('VmPosixSessionPure::getsid', $helper);
        $this->assertStringNotContainsString('FFI::cdef', $helper);
    }

    public function testPosixSessionJitHelperMatchesVmOnLinux(): void
    {
        if (!VmPosixSessionPure::available()) {
            $this->markTestSkipped('Linux /proc sessionid only');
        }

        $sid = VmPosix::getsid(0);
        $this->assertIsInt($sid);
        $this->assertSame($sid, PosixSessionJitHelper::getsid(0));

        $pgid = VmPosix::getpgid(0);
        $this->assertIsInt($pgid);
        $this->assertSame($pgid, PosixSessionJitHelper::getpgid(0));
    }

    public function testPosixSessionJitHelperSentinelOnInvalidPid(): void
    {
        if (!VmPosixSessionPure::available()) {
            $this->markTestSkipped('Linux /proc sessionid only');
        }

        $this->assertSame(-1, PosixSessionJitHelper::getsid(-1));
        $this->assertSame(-1, PosixSessionJitHelper::getpgid(-1));
    }
}
