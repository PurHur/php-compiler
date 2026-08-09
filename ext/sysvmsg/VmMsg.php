<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * System V message queues — host sysvmsg delegation (php-src ext/sysvmsg/sysvmsg.c; #3666).
 *
 * PHP-in-PHP: SysvMessageQueue VM objects map to host {@see \SysvMessageQueue} handles.
 */
final class VmMsg
{
    public const CLASS_LC = 'sysvmessagequeue';

    /** @var array<int, object> VM object id => host SysvMessageQueue */
    private static array $hostByObjectId = [];

    public static function available(): bool
    {
        return \function_exists('msg_get_queue');
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('SysvMessageQueue');
        $entry->isInternal = true;
        // php-src `final class SysvMessageQueue` (ext/sysvmsg/sysvmsg.stub.php; #28422).
        $entry->isFinal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function wrapHost(Context $ctx, object $host): Variable
    {
        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$hostByObjectId[$object->id] = $host;
        $var->object($object);

        return $var;
    }

    public static function hostForObject(ObjectEntry $object): ?object
    {
        if (0 !== strcasecmp($object->class->name, 'SysvMessageQueue')) {
            return null;
        }

        return self::$hostByObjectId[$object->id] ?? null;
    }

    public static function detachObject(ObjectEntry $object): void
    {
        unset(self::$hostByObjectId[$object->id]);
    }

    public static function isSysvMessageQueueObject(?ObjectEntry $object): bool
    {
        return null !== $object && 0 === strcasecmp($object->class->name, 'SysvMessageQueue');
    }

    /**
     * @return array{0: Variable|false, 1: string}
     */
    public static function getQueue(Context $ctx, int $key, ?int $permissions): array
    {
        if (!self::available()) {
            return [false, 'msg_get_queue() is not available in this compiler build'];
        }

        if (null === $permissions) {
            $host = @\msg_get_queue($key);
        } else {
            $host = @\msg_get_queue($key, $permissions);
        }

        if (false === $host || !\is_object($host)) {
            $last = \error_get_last();
            $message = \is_array($last) && isset($last['message']) ? (string) $last['message'] : 'msg_get_queue() failed';

            return [false, $message];
        }

        return [self::wrapHost($ctx, $host), ''];
    }

    public static function queueExists(int $key): bool
    {
        if (!self::available() || !\function_exists('msg_queue_exists')) {
            return false;
        }

        return @\msg_queue_exists($key);
    }

    /**
     * @return array{0: bool, 1: ?int, 2: string}
     */
    public static function send(
        object $host,
        int $messageType,
        mixed $message,
        bool $serialize,
        bool $blocking
    ): array {
        if (!self::available()) {
            return [false, null, 'msg_send() is not available in this compiler build'];
        }

        $errorCode = null;
        $ok = @\msg_send($host, $messageType, $message, $serialize, $blocking, $errorCode);
        if ($ok) {
            return [true, null, ''];
        }
        $last = \error_get_last();
        $messageText = \is_array($last) && isset($last['message']) ? (string) $last['message'] : 'msg_send() failed';

        return [false, \is_int($errorCode) ? $errorCode : null, $messageText];
    }

    /**
     * @return array{0: bool, 1: ?int, 2: mixed, 3: ?int, 4: string}
     */
    public static function receive(
        object $host,
        int $desiredMessageType,
        int $maxMessageSize,
        bool $unserialize,
        int $flags
    ): array {
        if (!self::available()) {
            return [false, null, null, null, 'msg_receive() is not available in this compiler build'];
        }

        $receivedType = 0;
        $message = null;
        $errorCode = null;
        $ok = @\msg_receive(
            $host,
            $desiredMessageType,
            $receivedType,
            $maxMessageSize,
            $message,
            $unserialize,
            $flags,
            $errorCode
        );
        if ($ok) {
            return [true, $receivedType, $message, null, ''];
        }
        $last = \error_get_last();
        $messageText = \is_array($last) && isset($last['message']) ? (string) $last['message'] : 'msg_receive() failed';

        return [false, null, null, \is_int($errorCode) ? $errorCode : null, $messageText];
    }

    public static function remove(object $host): bool
    {
        if (!self::available()) {
            return false;
        }

        return @\msg_remove_queue($host);
    }

    /**
     * @return array<string, int>|false
     */
    public static function stat(object $host): array|false
    {
        if (!self::available()) {
            return false;
        }

        $stat = @\msg_stat_queue($host);
        if (!\is_array($stat)) {
            return false;
        }

        return $stat;
    }

    /**
     * msg_set_queue() — update queue attributes from msg_stat_queue-shaped array (#21633).
     *
     * @param array<string|int, mixed> $data
     */
    public static function setQueue(object $host, array $data): bool
    {
        if (!self::available() || !\function_exists('msg_set_queue')) {
            return false;
        }

        return @\msg_set_queue($host, $data);
    }

    /**
     * @param array<string, int> $stat
     */
    public static function statToHashTable(array $stat): HashTable
    {
        $ht = new HashTable();
        foreach ($stat as $key => $value) {
            $slot = new Variable(Variable::TYPE_INTEGER);
            $slot->int((int) $value);
            $ht->add((string) $key, $slot);
        }

        return $ht;
    }
}
