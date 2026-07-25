<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\ext\sysvmsg\VmMsg;
use PHPCompiler\ext\sysvsem\VmSem;

/**
 * php-src @not-serializable System V IPC / shmop objects (#23132):
 * - SysvMessageQueue — ext/sysvmsg/sysvmsg.stub.php
 * - SysvSemaphore — ext/sysvsem/sysvsem.stub.php
 * - SysvSharedMemory — ext/sysvshm/sysvshm.stub.php
 * - Shmop — ext/shmop/shmop.stub.php (hosted under ext/sysvshm/)
 */
final class SysvIpcSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmMsg::CLASS_LC,
        VmSem::CLASS_LC,
        VmSysvShm::CLASS_LC,
        VmShmop::CLASS_LC,
    ];

    public static function rejectSerialization(string $className): void
    {
        if (self::isDenied($className)) {
            throw new \Exception("Serialization of '".self::displayName($className)."' is not allowed");
        }
    }

    public static function rejectUnserialization(string $className): void
    {
        if (self::isDenied($className)) {
            throw new \Exception("Unserialization of '".self::displayName($className)."' is not allowed");
        }
    }

    private static function isDenied(string $className): bool
    {
        return \in_array(strtolower(ltrim($className, '\\')), self::DENIED_LC, true);
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
