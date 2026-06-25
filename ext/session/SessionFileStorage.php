<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\VmSession;

/**
 * File-backed session blob I/O (php-src ext/session/mod_files.c; issue #6968).
 *
 * VM source of truth for storage paths and id sanitization; JIT/AOT lowering in
 * {@see \PHPCompiler\ext\standard\SessionStorageJitHelper} via {@see \PHPCompiler\JIT\Builtin\SessionStorageRuntime}.
 */
final class SessionFileStorage
{
    public const DEFAULT_DIR_SUFFIX = '/phpc_sessions';

    public const PATH_PREFIX = 'sess_';

    public static function storageDir(): string
    {
        $fromEnv = getenv('PHP_COMPILER_SESSION_DIR');
        if (false !== $fromEnv && '' !== $fromEnv) {
            return rtrim($fromEnv, '/\\');
        }

        return rtrim(sys_get_temp_dir(), '/\\').self::DEFAULT_DIR_SUFFIX;
    }

    public static function storagePath(string $id): string
    {
        return self::storageDir().'/'.self::PATH_PREFIX.$id;
    }

    public static function sanitizeId(string $id): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9,-]/', '', $id);

        return is_string($clean) ? $clean : '';
    }

    public static function maxIdLen(): int
    {
        return VmSession::MAX_ID_LEN;
    }
}
