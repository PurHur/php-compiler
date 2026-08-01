<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Opaque SSH2 session object (PECL ssh2 resource; #6385).
 */
final class VmSsh2Session
{
    public const CLASS_LC = 'ssh2\\session';

    public const CLASS_NAME = 'SSH2\\Session';

    /** @var array<int, array{host: string, port: int, closed: bool, authed: bool, object: ObjectEntry}> */
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

    public static function wrap(Context $ctx, string $host, int $port): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'host' => $host,
            'port' => $port,
            'closed' => false,
            'authed' => false,
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

    public static function requireLive(ObjectEntry $object, string $fn): ObjectEntry
    {
        if (!self::isLive($object)) {
            throw new \TypeError($fn.'(): supplied resource is not a valid SSH2 Session resource');
        }

        return $object;
    }

    public static function markAuthed(ObjectEntry $object): void
    {
        if (isset(self::$state[$object->id])) {
            self::$state[$object->id]['authed'] = true;
        }
    }

    public static function isAuthed(ObjectEntry $object): bool
    {
        return self::$state[$object->id]['authed'] ?? false;
    }

    public static function host(ObjectEntry $object): string
    {
        return self::$state[$object->id]['host'] ?? '';
    }

    public static function port(ObjectEntry $object): int
    {
        return self::$state[$object->id]['port'] ?? 22;
    }

    public static function close(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        self::$state[$object->id]['closed'] = true;

        return true;
    }
}
