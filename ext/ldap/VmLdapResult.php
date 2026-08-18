<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * LDAP\Result / LDAP\ResultEntry wrappers (php-src ext/ldap; #3369).
 */
final class VmLdapResult
{
    public const RESULT_CLASS_LC = 'ldap\\result';

    public const RESULT_CLASS_NAME = 'LDAP\\Result';

    public const ENTRY_CLASS_LC = 'ldap\\resultentry';

    public const ENTRY_CLASS_NAME = 'LDAP\\ResultEntry';

    /** @var array<int, array{native: \FFI\CData, freed: bool, connection_id: int, object: ObjectEntry}> */
    private static array $results = [];

    /** @var array<int, array{native: \FFI\CData, connection_id: int, result_id: int, ber: ?\FFI\CData}> */
    private static array $entries = [];

    /** @var list<int> object ids from wrapResult awaiting JIT handle registration (#32172) */
    private static array $pendingJitHandleIds = [];

    /** @var array<int, int> JIT object address (ptrToInt) => object id */
    private static array $jitHandleToId = [];

    public static function registerClasses(Context $ctx): void
    {
        if (!isset($ctx->classes[self::RESULT_CLASS_LC])) {
            $entry = new ClassEntry(self::RESULT_CLASS_NAME);
            $entry->isInternal = true;
            $ctx->classes[self::RESULT_CLASS_LC] = $entry;
        }
        if (!isset($ctx->classes[self::ENTRY_CLASS_LC])) {
            $entry = new ClassEntry(self::ENTRY_CLASS_NAME);
            $entry->isInternal = true;
            $ctx->classes[self::ENTRY_CLASS_LC] = $entry;
        }
    }

    public static function wrapResult(\FFI\CData $native, Context $ctx, ObjectEntry $connection): Variable
    {
        self::registerClasses($ctx);
        $object = new ObjectEntry($ctx->classes[self::RESULT_CLASS_LC]);
        $object->constructed = true;
        self::$results[$object->id] = [
            'native' => $native,
            'freed' => false,
            'connection_id' => $connection->id,
            'object' => $object,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    /** Enqueue object id after ldap_bind_ext() NestedJIT helper wrap (#32172). */
    public static function enqueuePendingJitHandle(int $objectId): void
    {
        self::$pendingJitHandleIds[] = $objectId;
    }

    /** Map compiled __object__* address to VM result state after Result wrap JIT (#32172). */
    public static function claimPendingJitHandle(int $handle): void
    {
        if ($handle <= 0 || [] === self::$pendingJitHandleIds) {
            return;
        }
        self::$jitHandleToId[$handle] = (int) \array_shift(self::$pendingJitHandleIds);
    }

    public static function resultForLookupKey(int $handle): ?ObjectEntry
    {
        if ($handle <= 0) {
            return null;
        }
        $id = self::$jitHandleToId[$handle] ?? null;
        if (null === $id || !isset(self::$results[$id])) {
            return null;
        }
        $object = self::$results[$id]['object'];
        if (!self::isLiveResult($object)) {
            return null;
        }

        return $object;
    }

    public static function isFreedLookupKey(int $handle): bool
    {
        if ($handle <= 0) {
            return false;
        }
        $id = self::$jitHandleToId[$handle] ?? null;
        if (null === $id || !isset(self::$results[$id])) {
            return false;
        }

        return (bool) self::$results[$id]['freed'];
    }

    public static function wrapEntry(\FFI\CData $native, Context $ctx, ObjectEntry $connection, int $resultId): Variable
    {
        self::registerClasses($ctx);
        $object = new ObjectEntry($ctx->classes[self::ENTRY_CLASS_LC]);
        $object->constructed = true;
        self::$entries[$object->id] = [
            'native' => $native,
            'connection_id' => $connection->id,
            'result_id' => $resultId,
            'ber' => null,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function entryResultId(ObjectEntry $entry): int
    {
        if (!isset(self::$entries[$entry->id])) {
            return 0;
        }

        return (int) self::$entries[$entry->id]['result_id'];
    }

    public static function entryBer(ObjectEntry $entry): ?\FFI\CData
    {
        if (!isset(self::$entries[$entry->id])) {
            return null;
        }

        return self::$entries[$entry->id]['ber'];
    }

    public static function setEntryBer(ObjectEntry $entry, ?\FFI\CData $ber): void
    {
        if (!isset(self::$entries[$entry->id])) {
            return;
        }
        self::$entries[$entry->id]['ber'] = $ber;
    }

    public static function isLiveResult(ObjectEntry $object): bool
    {
        return isset(self::$results[$object->id]) && !self::$results[$object->id]['freed'];
    }

    public static function isLiveEntry(ObjectEntry $object): bool
    {
        return isset(self::$entries[$object->id]);
    }

    public static function resultNative(ObjectEntry $object): \FFI\CData
    {
        if (!self::isLiveResult($object)) {
            throw new \TypeError('ldap_*: supplied LDAP\\Result is not a valid ldap result resource');
        }

        return self::$results[$object->id]['native'];
    }

    public static function entryNative(ObjectEntry $object): \FFI\CData
    {
        if (!self::isLiveEntry($object)) {
            throw new \TypeError('ldap_*: supplied LDAP\\ResultEntry is not a valid ldap result entry resource');
        }

        return self::$entries[$object->id]['native'];
    }

    public static function freeResult(ObjectEntry $object): bool
    {
        if (!isset(self::$results[$object->id]) || self::$results[$object->id]['freed']) {
            return false;
        }
        $native = self::$results[$object->id]['native'];
        self::$results[$object->id]['freed'] = true;
        try {
            VmLdapNative::msgFree($native);
        } catch (\Throwable) {
        }

        return true;
    }
}
