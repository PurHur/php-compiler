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

    /** NestedJIT / thin AOT — keyed by object address (peer {@see VmSocket}; #31357). */
    /** @var array<int, AddrinfoSnapshot> */
    private static array $jitSnapshots = [];

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

    /**
     * AddressInfo under NestedJIT — snapshot keyed by object address (#31357).
     *
     * @param AddrinfoSnapshot $snapshot
     */
    public static function registerJitSnapshot(int $objAddr, array $snapshot): void
    {
        if ($objAddr <= 0) {
            return;
        }
        self::$jitSnapshots[$objAddr] = $snapshot;
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
        return self::$snapshots[$object->id]
            ?? self::$jitSnapshots[$object->id]
            ?? null;
    }

    /**
     * @return AddrinfoSnapshot|null
     */
    public static function snapshotForLookupKey(int $key): ?array
    {
        if ($key <= 0) {
            return null;
        }

        return self::$jitSnapshots[$key] ?? self::$snapshots[$key] ?? null;
    }

    /** @var array{ai_flags: int, ai_family: int, ai_socktype: int, ai_protocol: int, ai_addr: array<string, int|string>}|null */
    private static ?array $lastExplain = null;

    public static function setLastExplain(array $explain): void
    {
        self::$lastExplain = $explain;
    }

    /** @return array{ai_flags: int, ai_family: int, ai_socktype: int, ai_protocol: int, ai_addr: array<string, int|string>}|null */
    public static function lastExplain(): ?array
    {
        return self::$lastExplain;
    }

    public static function release(ObjectEntry $object): void
    {
        unset(self::$snapshots[$object->id], self::$jitSnapshots[$object->id]);
    }
}
