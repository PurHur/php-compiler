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

    /**
     * Path-based save for thin AOT — path resolved with libc getenv in the LLVM bridge (#21900).
     */
    /**
     * Path-based save for thin AOT — path resolved with libc getenv in the LLVM bridge (#21900).
     *
     * Prefer {@see JitSessionStorageKernel} LLVM wire save for string scalars; this remains
     * for embed / non-string values.
     */
    public static function saveToPath(string $path, HashTable $session): void
    {
        if ('' === $path) {
            return;
        }
        $payload = VmSessionSerializer::encodeWireHashTable($session);
        if (false === $payload) {
            return;
        }
        \file_put_contents($path, $payload);
    }

    /**
     * Path-based load for thin AOT (#21900).
     */
    public static function loadFromPath(string $path, HashTable $dest): void
    {
        if ('' === $path) {
            return;
        }
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

    /**
     * Path-based unlink for thin AOT (#21900).
     */
    public static function unlinkPath(string $path): void
    {
        if ('' === $path) {
            return;
        }
        VmFsUnlink::unlink($path);
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
        // HashTable::find() nested-JIT via HashTableFind + HashTableNestedReceiver (#21849).
        // Cookie apply for AOT uses libc getenv in JitSessionStorageKernel (#21900) —
        // do not call getenv/strpos here (NestedJIT segfault + thin-AOT link miss).
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
