<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\session\SessionFileStorage;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Session file I/O for compiled JIT/AOT modules (#9495, php-in-PHP).
 *
 * SSOT: {@see VmSession} load/save paths, {@see SessionFileStorage}, {@see VmSessionSerializer}
 * php-src: ext/session/mod_files.c
 */
final class SessionStorageJitHelper
{
    public static function mergeHashTables(HashTable $dest, HashTable $src): void
    {
        $dest->mergeStringKeysFrom($src, true);
    }

    public static function loadFromDisk(string $sessionId, HashTable $dest): void
    {
        if ('' === $sessionId) {
            return;
        }
        $path = SessionFileStorage::storagePath($sessionId);
        if (!VmStatPath::isFile($path)) {
            return;
        }
        $raw = VmFsReadNative::read($path);
        if (false === $raw || '' === $raw) {
            return;
        }
        $decoded = VmSessionSerializer::decodeWireHashTable($raw);
        if (null === $decoded) {
            return;
        }
        self::mergeHashTables($dest, $decoded);
    }

    public static function saveToDisk(string $sessionId, HashTable $session): void
    {
        if ('' === $sessionId) {
            return;
        }
        $payload = VmSessionSerializer::encodeWireHashTable($session);
        if (false === $payload) {
            return;
        }
        $dir = SessionFileStorage::storageDir();
        if (!VmStatPath::isDir($dir)) {
            VmFs::mkdir($dir, 0700, true);
        }
        VmFs::filePutContents(SessionFileStorage::storagePath($sessionId), $payload, \LOCK_EX);
    }

    public static function unlinkFile(string $sessionId): void
    {
        if ('' === $sessionId) {
            return;
        }
        VmFsUnlink::unlink(SessionFileStorage::storagePath($sessionId));
    }

    public static function readCookieId(string $sessionName, ?HashTable $cookies): string
    {
        if ('' === $sessionName || null === $cookies) {
            return '';
        }
        $val = $cookies->find($sessionName);
        if (null === $val) {
            return '';
        }
        $val = $val->resolveIndirect();
        if (Variable::TYPE_STRING !== $val->type) {
            return '';
        }

        return SessionFileStorage::sanitizeId($val->toString());
    }
}
