<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Opaque SSH2 publickey subsystem (PECL SSH2 Publickey Subsystem; #26717).
 */
final class VmSsh2Publickey
{
    public const CLASS_LC = 'ssh2\\publickey';

    public const CLASS_NAME = 'SSH2\\Publickey';

    /**
     * @var array<int, array{
     *   session: ObjectEntry,
     *   closed: bool,
     *   pkey: \FFI\CData|null
     * }>
     */
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

    /**
     * @param \FFI\CData|null $pkey LIBSSH2_PUBLICKEY*
     */
    public static function wrap(
        Context $ctx,
        ObjectEntry $session,
        $pkey = null
    ): Variable {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'session' => $session,
            'closed' => false,
            'pkey' => $pkey,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    public static function requireLive(ObjectEntry $object, string $fn): ObjectEntry
    {
        if (!self::isLive($object)) {
            throw new \TypeError($fn.'(): supplied resource is not a valid SSH2 Publickey Subsystem resource');
        }

        return $object;
    }

    public static function session(ObjectEntry $object): ObjectEntry
    {
        return self::$state[$object->id]['session'];
    }

    /** @return \FFI\CData|null LIBSSH2_PUBLICKEY* */
    public static function nativePublickey(ObjectEntry $object)
    {
        return self::$state[$object->id]['pkey'] ?? null;
    }

    public static function close(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return;
        }
        $st = &self::$state[$object->id];
        if (null !== $st['pkey']) {
            VmSsh2Native::publickeyShutdown($st['pkey']);
            $st['pkey'] = null;
        }
        $st['closed'] = true;
    }
}
