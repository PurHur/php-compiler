<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Opaque SSH2 SFTP resource (PECL ssh2; #26510).
 */
final class VmSsh2Sftp
{
    public const CLASS_LC = 'ssh2\\sftp';

    public const CLASS_NAME = 'SSH2\\Sftp';

    /**
     * @var array<int, array{
     *   session: ObjectEntry,
     *   closed: bool,
     *   sftp: \FFI\CData|null
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
     * @param \FFI\CData|null $sftp LIBSSH2_SFTP*
     */
    public static function wrap(Context $ctx, ObjectEntry $session, $sftp = null): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'session' => $session,
            'closed' => false,
            'sftp' => $sftp,
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
            throw new \TypeError($fn.'(): supplied resource is not a valid SSH2 SFTP resource');
        }

        return $object;
    }

    /** @return \FFI\CData|null LIBSSH2_SFTP* */
    public static function nativeSftp(ObjectEntry $object)
    {
        return self::$state[$object->id]['sftp'] ?? null;
    }

    public static function close(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return;
        }
        $st = &self::$state[$object->id];
        if (null !== $st['sftp']) {
            VmSsh2Native::sftpShutdown($st['sftp']);
            $st['sftp'] = null;
        }
        $st['closed'] = true;
    }
}
