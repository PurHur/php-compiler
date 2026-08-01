<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Opaque SSH2 remote forward listener (PECL SSH2 Listener; #26715).
 */
final class VmSsh2Listener
{
    public const CLASS_LC = 'ssh2\\listener';

    public const CLASS_NAME = 'SSH2\\Listener';

    /**
     * @var array<int, array{
     *   session: ObjectEntry,
     *   port: int,
     *   host: string|null,
     *   closed: bool,
     *   listener: \FFI\CData|null
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
     * @param \FFI\CData|null $listener LIBSSH2_LISTENER*
     */
    public static function wrap(
        Context $ctx,
        ObjectEntry $session,
        int $port,
        ?string $host,
        $listener = null
    ): Variable {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'session' => $session,
            'port' => $port,
            'host' => $host,
            'closed' => false,
            'listener' => $listener,
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
            throw new \TypeError($fn.'(): supplied resource is not a valid SSH2 Listener resource');
        }

        return $object;
    }

    public static function session(ObjectEntry $object): ObjectEntry
    {
        return self::$state[$object->id]['session'];
    }

    /** @return \FFI\CData|null LIBSSH2_LISTENER* */
    public static function nativeListener(ObjectEntry $object)
    {
        return self::$state[$object->id]['listener'] ?? null;
    }

    public static function close(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return;
        }
        $st = &self::$state[$object->id];
        if (null !== $st['listener']) {
            VmSsh2Native::channelForwardCancel($st['listener']);
            $st['listener'] = null;
        }
        $st['closed'] = true;
    }
}
