<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * is_resource/fclose/feof/fflush/pclose for compiled JIT/AOT embed modules (#9442).
 *
 * SSOT: {@see VmFs}, {@see VmDir}, {@see VmProcess}, {@see StreamLibcHandleJitHelper}
 * php-src: ext/standard/streamsfuncs.c, ext/standard/file.c
 */
final class StreamLifecycleJitHelper
{
    /** @return 0|1 ABI for __compiler_is_resource */
    public static function isResourceArgv(int $handle): int
    {
        if ($handle <= 0) {
            return 0;
        }
        if (VmDir::isValidHandle($handle)) {
            return 1;
        }
        if (VmProcess::isValidHandle($handle)) {
            return 1;
        }
        if (VmFs::isValidHandle($handle)) {
            return 1;
        }
        if ($handle <= 2) {
            return 0;
        }

        return StreamLibcHandleJitHelper::isOpen($handle) ? 1 : 0;
    }

    /** @return 0|1 ABI for __compiler_fclose */
    public static function fcloseArgv(int $handle): int
    {
        if (VmFs::isValidHandle($handle)) {
            return VmFs::fclose($handle) ? 1 : 0;
        }
        if (StreamLibcHandleJitHelper::isOpen($handle)) {
            return StreamLibcHandleJitHelper::fclose($handle) ? 1 : 0;
        }

        return 0;
    }

    /** @return 0|1 ABI for __compiler_feof */
    public static function feofArgv(int $handle): int
    {
        if (VmFs::isValidHandle($handle)) {
            return VmFs::feof($handle) ? 1 : 0;
        }
        if (!StreamLibcHandleJitHelper::isOpen($handle)) {
            return 1;
        }

        return StreamLibcHandleJitHelper::feof($handle) ? 1 : 0;
    }

    /** @return 0|1 ABI for __compiler_fflush */
    public static function fflushArgv(int $handle): int
    {
        if (VmFs::isValidHandle($handle)) {
            return VmFs::fflush($handle) ? 1 : 0;
        }
        if (!StreamLibcHandleJitHelper::isOpen($handle)) {
            return 0;
        }

        return StreamLibcHandleJitHelper::fflush($handle) ? 1 : 0;
    }

    /** @return int ABI for __compiler_pclose (status or -1) */
    public static function pcloseArgv(int $handle): int
    {
        return StreamLibcHandleJitHelper::pclose($handle);
    }
}
