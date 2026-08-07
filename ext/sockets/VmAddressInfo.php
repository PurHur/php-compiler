<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * AddressInfo builtin class — opaque getaddrinfo result (php-src ext/sockets; #6064).
 *
 * @phpstan-type AddrinfoSnapshot array{
 *   ai_flags: int,
 *   ai_family: int,
 *   ai_socktype: int,
 *   ai_protocol: int,
 *   ai_addr: string,
 *   ai_canonname: ?string
 * }
 */
final class VmAddressInfo
{
    public const CLASS_LC = 'addressinfo';

    /** @var array<int, AddrinfoSnapshot> */
    private static array $snapshots = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('AddressInfo');
        $entry->isInternal = true;
        // php-src `final class AddressInfo` (ext/sockets/sockets.stub.php; #28391).
        $entry->isFinal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * @param AddrinfoSnapshot $snapshot
     */
    public static function wrap(array $snapshot, Context $ctx): ObjectEntry
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$snapshots[$object->id] = $snapshot;

        return $object;
    }

    public static function isAddressInfoObject(?ObjectEntry $object): bool
    {
        return null !== $object && 0 === \strcasecmp($object->class->name, 'AddressInfo');
    }

    /**
     * @return AddrinfoSnapshot|null
     */
    public static function snapshotFor(ObjectEntry $object): ?array
    {
        return self::$snapshots[$object->id] ?? null;
    }

    public static function release(ObjectEntry $object): void
    {
        unset(self::$snapshots[$object->id]);
    }
}
