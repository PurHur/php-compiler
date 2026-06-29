<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\posix\posix_ctermid;
use PHPCompiler\ext\posix\posix_errno;
use PHPCompiler\ext\posix\posix_get_last_error;
use PHPCompiler\ext\posix\posix_getcwd;
use PHPCompiler\ext\posix\posix_getpid;
use PHPCompiler\ext\posix\posix_getppid;
use PHPCompiler\ext\posix\posix_strerror;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for posix v1 wrappers (#7271). */
final class PosixGetpidBuiltinTest extends TestCase
{
    public function test_posix_getpid_returns_host_pid(): void
    {
        $runtime = new Runtime();
        $fn = new posix_getpid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame(\getmypid(), $frame->returnVar->resolveIndirect()->toInt());
    }

    public function test_posix_getppid_returns_positive_int(): void
    {
        $runtime = new Runtime();
        $fn = new posix_getppid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame((int) \posix_getppid(), $frame->returnVar->resolveIndirect()->toInt());
    }

    public function test_posix_strerror_zero_is_non_empty(): void
    {
        $runtime = new Runtime();
        $fn = new posix_strerror();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[0]->int(0);
        $fn->execute($frame);
        $msg = $frame->returnVar->resolveIndirect()->toString();
        $this->assertSame('Success', $msg);
        $this->assertSame((string) \posix_strerror(0), $msg);
    }

    public function test_posix_get_last_error_defaults_to_zero(): void
    {
        $runtime = new Runtime();
        $fn = new posix_get_last_error();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame(0, $frame->returnVar->resolveIndirect()->toInt());
    }

    public function test_posix_errno_matches_get_last_error(): void
    {
        $runtime = new Runtime();
        $fn = new posix_errno();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame(0, $frame->returnVar->resolveIndirect()->toInt());
    }

    public function test_posix_getcwd_returns_non_empty_string(): void
    {
        $runtime = new Runtime();
        $fn = new posix_getcwd();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $cwd = $frame->returnVar->resolveIndirect()->toString();
        $this->assertNotSame('', $cwd);
        $this->assertTrue(\is_dir($cwd));
    }

    public function test_posix_ctermid_returns_string(): void
    {
        $runtime = new Runtime();
        $fn = new posix_ctermid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame(
            VMVariable::TYPE_STRING,
            $frame->returnVar->resolveIndirect()->type
        );
    }
}
