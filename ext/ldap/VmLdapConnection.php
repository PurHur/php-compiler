<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * LDAP\Connection object + link state (php-src ext/ldap; #3369).
 */
final class VmLdapConnection
{
    public const CLASS_LC = 'ldap\\connection';

    public const CLASS_NAME = 'LDAP\\Connection';

    /** @var array<int, array{native: \FFI\CData, closed: bool, errno: int, object: ObjectEntry, rebind_callback: ?Variable, rebind_ctx: ?Context, rebind_params: ?\FFI\CData}> */
    private static array $state = [];

    /** @var list<int> object ids from connectUri awaiting JIT handle registration (#32001) */
    private static array $pendingJitHandleIds = [];

    /** @var array<int, int> JIT object address (ptrToInt) => object id */
    private static array $jitHandleToId = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function wrap(\FFI\CData $native, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'native' => $native,
            'closed' => false,
            'errno' => VmLdapNative::LDAP_SUCCESS,
            'object' => $object,
            'rebind_callback' => null,
            'rebind_ctx' => null,
            'rebind_params' => null,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    /** Enqueue object id after ldap_connect() NestedJIT helper wrap (#32001). */
    public static function enqueuePendingJitHandle(int $objectId): void
    {
        self::$pendingJitHandleIds[] = $objectId;
    }

    /** Map compiled __object__* address to VM link state after ldap_connect() JIT (#32001). */
    public static function claimPendingJitHandle(int $handle): void
    {
        if ($handle <= 0 || [] === self::$pendingJitHandleIds) {
            return;
        }
        self::$jitHandleToId[$handle] = (int) \array_shift(self::$pendingJitHandleIds);
    }

    public static function connectionForLookupKey(int $handle): ?ObjectEntry
    {
        if ($handle <= 0) {
            return null;
        }
        $id = self::$jitHandleToId[$handle] ?? null;
        if (null === $id || !isset(self::$state[$id])) {
            return null;
        }
        $object = self::$state[$id]['object'];
        if (!self::isLive($object)) {
            return null;
        }

        return $object;
    }

    public static function isClosedLookupKey(int $handle): bool
    {
        if ($handle <= 0) {
            return false;
        }
        $id = self::$jitHandleToId[$handle] ?? null;
        if (null === $id || !isset(self::$state[$id])) {
            return false;
        }

        return self::$state[$id]['closed'];
    }

    public static function native(ObjectEntry $object): \FFI\CData
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            throw new \TypeError('ldap_*: supplied LDAP\\Connection is not a valid ldap link resource');
        }

        return self::$state[$object->id]['native'];
    }

    public static function setErrno(ObjectEntry $object, int $errno): void
    {
        if (!isset(self::$state[$object->id])) {
            return;
        }
        self::$state[$object->id]['errno'] = $errno;
    }

    public static function errno(ObjectEntry $object): int
    {
        if (!isset(self::$state[$object->id])) {
            return 0;
        }

        return (int) self::$state[$object->id]['errno'];
    }

    public static function close(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        self::clearRebindProc($object);
        $native = self::$state[$object->id]['native'];
        self::$state[$object->id]['closed'] = true;
        try {
            VmLdapNative::unbind($native);
        } catch (\Throwable) {
        }

        return true;
    }

    /**
     * ldap_set_rebind_proc — store callable + OpenLDAP trampoline (#22226).
     */
    public static function setRebindProc(ObjectEntry $object, ?Variable $callback, ?Context $ctx): void
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            throw new \Error('LDAP connection has already been closed');
        }
        $ld = self::$state[$object->id]['native'];
        if (null === $callback) {
            self::clearRebindProc($object);

            return;
        }
        $stored = new Variable();
        $stored->copyFrom($callback->resolveIndirect());
        $idBox = \FFI::new('int');
        $idBox->cdata = $object->id;
        // Keep previous box alive until after set succeeds.
        self::$state[$object->id]['rebind_callback'] = $stored;
        self::$state[$object->id]['rebind_ctx'] = $ctx;
        self::$state[$object->id]['rebind_params'] = $idBox;
        VmLdapNative::setRebindProc($ld, \FFI::addr($idBox));
    }

    public static function clearRebindProc(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id])) {
            return;
        }
        try {
            if (!self::$state[$object->id]['closed']) {
                VmLdapNative::clearRebindProc(self::$state[$object->id]['native']);
            }
        } catch (\Throwable) {
        }
        self::$state[$object->id]['rebind_callback'] = null;
        self::$state[$object->id]['rebind_ctx'] = null;
        self::$state[$object->id]['rebind_params'] = null;
    }

    /**
     * OpenLDAP rebind trampoline target (php-src _ldap_rebind_proc).
     *
     * @return array{callback: Variable, ctx: Context, object: ObjectEntry}|null
     */
    public static function rebindStateForId(int $id): ?array
    {
        if (!isset(self::$state[$id]) || self::$state[$id]['closed']) {
            return null;
        }
        $cb = self::$state[$id]['rebind_callback'];
        $ctx = self::$state[$id]['rebind_ctx'];
        if (null === $cb || null === $ctx) {
            return null;
        }

        return [
            'callback' => $cb,
            'ctx' => $ctx,
            'object' => self::$state[$id]['object'],
        ];
    }
}
