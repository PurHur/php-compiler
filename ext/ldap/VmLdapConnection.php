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

    /** @var array<int, array{native: \FFI\CData, closed: bool, errno: int, object: ObjectEntry}> */
    private static array $state = [];

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
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
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
        $native = self::$state[$object->id]['native'];
        self::$state[$object->id]['closed'] = true;
        try {
            VmLdapNative::unbind($native);
        } catch (\Throwable) {
        }

        return true;
    }
}
