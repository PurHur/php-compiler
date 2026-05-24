<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\ResponseContext;

/**
 * VM session state for session_* builtins (issues #64, #1182–#1186).
 *
 * File-backed $_SESSION persistence under sys temp or PHP_COMPILER_SESSION_DIR.
 */
final class VmSession
{
    private static bool $active = false;

    private static string $name = 'PHPSESSID';

    private static string $id = '';

    public static function reset(): void
    {
        self::$active = false;
        self::$name = 'PHPSESSID';
        self::$id = '';
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    public static function getName(): string
    {
        return self::$name;
    }

    /**
     * @return string|false
     * previous name, or false when session is active
     */
    public static function setName(string $name) {
        if (self::$active) {
            return false;
        }
        if ('' === $name) {
            return false;
        }
        $previous = self::$name;
        self::$name = $name;

        return $previous;
    }

    public static function getId(): string
    {
        return self::$id;
    }

    /**
     * @return string|false
     * previous id, or false when $id is empty
     */
    public static function setId(string $id) {
        $sanitized = self::sanitizeId($id);
        if ('' === $sanitized) {
            return false;
        }
        $previous = self::$id;
        self::$id = $sanitized;

        return $previous;
    }

    public static function start(Context $ctx): bool
    {
        if (self::$active) {
            return false;
        }
        $incomingId = self::readCookieId($ctx);
        if ('' !== $incomingId) {
            self::$id = $incomingId;
        } else {
            self::$id = self::generateId();
            ResponseContext::addHeader(
                SetcookieLine::build(self::$name, self::$id, 0, '/'),
                false
            );
        }
        self::loadSession($ctx);
        self::$active = true;

        return true;
    }

    public static function writeClose(Context $ctx): bool
    {
        if (!self::$active) {
            return false;
        }
        self::saveSession($ctx);
        self::$active = false;

        return true;
    }

    public static function destroy(Context $ctx): bool
    {
        if (!self::$active) {
            return false;
        }
        if ('' !== self::$id) {
            $path = self::storagePath(self::$id);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $ctx->ensureSuperglobal('_SESSION')->array(new HashTable());
        self::$active = false;
        self::$id = '';

        return true;
    }

    public static function regenerateId(Context $ctx, bool $deleteOld): bool
    {
        if (!self::$active) {
            return false;
        }
        self::saveSession($ctx);
        $oldId = self::$id;
        if ($deleteOld && '' !== $oldId) {
            $oldPath = self::storagePath($oldId);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
        self::$id = self::generateId();
        ResponseContext::addHeader(
            SetcookieLine::build(self::$name, self::$id, 0, '/'),
            false
        );

        return true;
    }

    private static function readCookieId(Context $ctx): string
    {
        $cookieVar = $ctx->getSuperglobal('_COOKIE');
        if (null === $cookieVar) {
            return '';
        }
        $found = $cookieVar->toArray()->find(self::$name);
        if (null === $found) {
            return '';
        }

        return self::sanitizeId($found->resolveIndirect()->toString());
    }

    private static function loadSession(Context $ctx): void
    {
        $sessionVar = $ctx->ensureSuperglobal('_SESSION');
        if ('' === self::$id) {
            $sessionVar->array(new HashTable());

            return;
        }
        $path = self::storagePath(self::$id);
        if (!is_file($path)) {
            $sessionVar->array(new HashTable());

            return;
        }
        $raw = file_get_contents($path);
        if (false === $raw || '' === $raw) {
            $sessionVar->array(new HashTable());

            return;
        }
        $decoded = @unserialize($raw);
        if (!is_array($decoded)) {
            $sessionVar->array(new HashTable());

            return;
        }
        $sessionVar->array(self::importArray($decoded));
    }

    private static function saveSession(Context $ctx): void
    {
        if ('' === self::$id) {
            return;
        }
        $sessionVar = $ctx->getSuperglobal('_SESSION');
        if (null === $sessionVar) {
            return;
        }
        $exported = VmJson::export($sessionVar);
        $payload = serialize($exported);
        $dir = self::storageDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        file_put_contents(self::storagePath(self::$id), $payload, LOCK_EX);
    }

    private static function importArray(array $decoded): HashTable
    {
        $ht = new HashTable();
        $isList = array_is_list($decoded);
        foreach ($decoded as $key => $item) {
            $slot = VmJson::import($item);
            if ($isList) {
                $ht->addIndex((int) $key, $slot);
            } else {
                $ht->add((string) $key, $slot);
            }
        }

        return $ht;
    }

    private static function storageDir(): string
    {
        $fromEnv = getenv('PHP_COMPILER_SESSION_DIR');
        if (false !== $fromEnv && '' !== $fromEnv) {
            return rtrim($fromEnv, '/\\');
        }

        return rtrim(sys_get_temp_dir(), '/\\').'/phpc_sessions';
    }

    private static function storagePath(string $id): string
    {
        return self::storageDir().'/sess_'.$id;
    }

    private static function generateId(): string
    {
        return bin2hex(VmString::randomBytes(16));
    }

    private static function sanitizeId(string $id): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9,-]/', '', $id);

        return is_string($clean) ? $clean : '';
    }
}
