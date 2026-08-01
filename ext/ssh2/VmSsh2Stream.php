<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Opaque SSH2 channel/stream object (PECL ssh2 stream; #6385 / #26663).
 */
final class VmSsh2Stream
{
    public const CLASS_LC = 'ssh2\\stream';

    public const CLASS_NAME = 'SSH2\\Stream';

    /**
     * @var array<int, array{
     *   session: ObjectEntry,
     *   command: string,
     *   closed: bool,
     *   channel: \FFI\CData|null
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
     * @param \FFI\CData|null $channel LIBSSH2_CHANNEL*
     */
    public static function wrap(Context $ctx, ObjectEntry $session, string $command, $channel = null): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'session' => $session,
            'command' => $command,
            'closed' => false,
            'channel' => $channel,
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
            throw new \TypeError($fn.'(): supplied resource is not a valid SSH2 Stream resource');
        }

        return $object;
    }

    /** @return \FFI\CData|null LIBSSH2_CHANNEL* */
    public static function nativeChannel(ObjectEntry $object)
    {
        return self::$state[$object->id]['channel'] ?? null;
    }

    public static function close(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return;
        }
        $st = &self::$state[$object->id];
        if (null !== $st['channel']) {
            VmSsh2Native::channelFree($st['channel']);
            $st['channel'] = null;
        }
        $st['closed'] = true;
    }
}
