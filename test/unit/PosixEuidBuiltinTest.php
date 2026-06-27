<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\posix\posix_access;
use PHPCompiler\ext\posix\posix_getegid;
use PHPCompiler\ext\posix\posix_geteuid;
use PHPCompiler\ext\posix\posix_getgroups;
use PHPCompiler\ext\posix\posix_uname;
use PHPCompiler\ext\posix\PosixConstants;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for posix effective identity + uname (#6123); pure /proc path (#12395). */
final class PosixEuidBuiltinTest extends TestCase
{
    public function test_posix_geteuid_matches_host(): void
    {
        if (!\function_exists('posix_geteuid')) {
            $this->markTestSkipped('host posix_geteuid unavailable');
        }

        $runtime = new Runtime();
        $fn = new posix_geteuid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame((int) \posix_geteuid(), $frame->returnVar->resolveIndirect()->toInt());
    }

    public function test_posix_getegid_matches_host(): void
    {
        if (!\function_exists('posix_getegid')) {
            $this->markTestSkipped('host posix_getegid unavailable');
        }

        $runtime = new Runtime();
        $fn = new posix_getegid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame((int) \posix_getegid(), $frame->returnVar->resolveIndirect()->toInt());
    }

    public function test_posix_getgroups_returns_list(): void
    {
        if (!\function_exists('posix_getgroups')) {
            $this->markTestSkipped('host posix_getgroups unavailable');
        }

        $runtime = new Runtime();
        $fn = new posix_getgroups();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_ARRAY, $resolved->type);
        $host = \posix_getgroups();
        $this->assertIsArray($host);
        $vm = $resolved->toArray();
        $this->assertSame(\count($host), $vm->getNumElements());
    }

    public function test_posix_uname_has_required_keys(): void
    {
        $runtime = new Runtime();
        $fn = new posix_uname();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_ARRAY, $resolved->type);
        $vm = $resolved->toArray();
        foreach (['sysname', 'nodename', 'release', 'version', 'machine'] as $key) {
            $slot = $vm->find($key);
            $this->assertNotNull($slot, $key);
            $this->assertSame(VMVariable::TYPE_STRING, $slot->resolveIndirect()->type, $key);
            $this->assertNotSame('', $slot->resolveIndirect()->toString(), $key);
        }
    }

    public function testVmPosixRoutesIdentityThroughPurePaths(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertStringContainsString('VmProcessIdentityPure::geteuid', $source);
        $this->assertStringContainsString('VmProcessIdentityPure::getegid', $source);
        $this->assertStringContainsString('VmProcessIdentityPure::getgroups', $source);
        $this->assertStringContainsString('VmProcessIdentityPure::getppid', $source);
        $this->assertStringContainsString('VmUnamePure::utsname', $source);
        $this->assertStringContainsString('VmFsAccessPure::access', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->access\(/', $source);
    }

    public function test_posix_identity_matches_host_with_ffi_disabled_on_linux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\function_exists('posix_geteuid')) {
            $this->markTestSkipped('Linux host posix parity probe only');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $runtime = new Runtime();
            $cases = [
                [posix_geteuid::class, (int) \posix_geteuid()],
                [posix_getegid::class, (int) \posix_getegid()],
            ];
            foreach ($cases as [$class, $expected]) {
                $fn = new $class();
                $frame = $fn->getFrame($runtime->vmContext);
                $frame->returnVar = new VMVariable();
                $fn->execute($frame);
                $this->assertSame($expected, $frame->returnVar->resolveIndirect()->toInt(), $class);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function test_posix_access_matches_host_with_ffi_disabled_on_linux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\function_exists('posix_access')) {
            $this->markTestSkipped('Linux host posix_access parity probe only');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $runtime = new Runtime();
            $fn = new posix_access();
            $frame = $fn->getFrame($runtime->vmContext);
            $frame->returnVar = new VMVariable();
            $frame->calledArgs[] = new VMVariable();
            $frame->calledArgs[0]->string(__FILE__);
            $frame->calledArgs[] = new VMVariable();
            $frame->calledArgs[1]->int(PosixConstants::POSIX_R_OK);
            $fn->execute($frame);
            $this->assertSame(
                (bool) \posix_access(__FILE__, PosixConstants::POSIX_R_OK),
                $frame->returnVar->resolveIndirect()->toBool()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
