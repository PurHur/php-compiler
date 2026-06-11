<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\posix\posix_getegid;
use PHPCompiler\ext\posix\posix_geteuid;
use PHPCompiler\ext\posix\posix_getgroups;
use PHPCompiler\ext\posix\posix_uname;
use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for posix effective identity + uname (#6123). */
final class PosixEuidBuiltinTest extends TestCase
{
    protected function setUp(): void
    {
        if (!VmPosix::ffiAvailable()) {
            $this->markTestSkipped('libc FFI unavailable');
        }
    }

    public function test_posix_geteuid_matches_host(): void
    {
        $runtime = new Runtime();
        $fn = new posix_geteuid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame((int) \posix_geteuid(), $frame->returnVar->resolveIndirect()->toInt());
    }

    public function test_posix_getegid_matches_host(): void
    {
        $runtime = new Runtime();
        $fn = new posix_getegid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame((int) \posix_getegid(), $frame->returnVar->resolveIndirect()->toInt());
    }

    public function test_posix_getgroups_returns_list(): void
    {
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
}
