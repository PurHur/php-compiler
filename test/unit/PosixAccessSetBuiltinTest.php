<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\posix\posix_access;
use PHPCompiler\ext\posix\posix_mknod;
use PHPCompiler\ext\posix\posix_setegid;
use PHPCompiler\ext\posix\PosixConstants;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for posix access/mknod/set* (#7376). */
final class PosixAccessSetBuiltinTest extends TestCase
{
    public function test_posix_access_function_registered(): void
    {
        $runtime = new Runtime();
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'posix_access'));
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'posix_mknod'));
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'posix_setuid'));
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'posix_setgid'));
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'posix_seteuid'));
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'posix_setegid'));
    }

    public function test_posix_access_readable_file(): void
    {
        if (!\function_exists('posix_access')) {
            self::markTestSkipped('host posix_access unavailable');
        }
        $runtime = new Runtime();
        $fn = new posix_access();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[0]->string(__FILE__);
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[1]->int(PosixConstants::POSIX_R_OK);
        $fn->execute($frame);
        self::assertSame(
            (bool) \posix_access(__FILE__, PosixConstants::POSIX_R_OK),
            $frame->returnVar->resolveIndirect()->toBool()
        );
    }

    public function test_posix_access_missing_path_returns_false(): void
    {
        if (!\function_exists('posix_access')) {
            self::markTestSkipped('host posix_access unavailable');
        }
        $missing = sys_get_temp_dir().'/phpc_posix_missing_'.getmypid().'_'.uniqid('', true);
        $runtime = new Runtime();
        $fn = new posix_access();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[0]->string($missing);
        $fn->execute($frame);
        self::assertFalse($frame->returnVar->resolveIndirect()->toBool());
    }

    public function test_posix_mknod_fifo(): void
    {
        if (!\function_exists('posix_mknod')) {
            self::markTestSkipped('host posix_mknod unavailable');
        }
        $path = sys_get_temp_dir().'/phpc_posix_mknod_'.getmypid();
        @\unlink($path);
        $mode = PosixConstants::S_IFIFO | 0644;
        $expected = (bool) @\posix_mknod($path, $mode);
        @\unlink($path);
        $runtime = new Runtime();
        $fn = new posix_mknod();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[0]->string($path);
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[1]->int($mode);
        $fn->execute($frame);
        self::assertSame($expected, $frame->returnVar->resolveIndirect()->toBool());
        @\unlink($path);
    }

    public function test_posix_setegid_restore_current(): void
    {
        if (!\function_exists('posix_setegid') || !\function_exists('posix_getegid')) {
            self::markTestSkipped('host posix_setegid unavailable');
        }
        $egid = (int) \posix_getegid();
        $runtime = new Runtime();
        $fn = new posix_setegid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[0]->int($egid);
        $fn->execute($frame);
        self::assertSame(
            (bool) @\posix_setegid($egid),
            $frame->returnVar->resolveIndirect()->toBool()
        );
    }
}
