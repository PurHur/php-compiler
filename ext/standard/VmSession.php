<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\session\SessionConstants;
use PHPCompiler\ext\session\SessionFileStorage;
use PHPCompiler\Frame;
use PHPCompiler\VM\BackedEnum;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\SapiOutput;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\ResponseContext;

/**
 * VM session state for session_* builtins (issues #64, #1182–#1186).
 *
 * File-backed $_SESSION persistence under sys temp or PHP_COMPILER_SESSION_DIR.
 * File I/O via VmFs* / VmDirNative — no host mkdir/unlink/write builtins (#8514).
 */
final class VmSession
{
    /** Limits shared with {@see \PHPCompiler\JIT\Builtin\SessionStorageGlobals} (#5694, #5750). */
    public const MAX_ID_LEN = 128;

    public const MAX_NAME_LEN = 128;

    public const DEFAULT_NAME = 'PHPSESSID';

    public const DEFAULT_MODULE = 'files';

    public const MAX_MODULE_LEN = 32;

    private static bool $active = false;

    private static string $name = self::DEFAULT_NAME;

    private static string $id = '';

    private static string $moduleName = self::DEFAULT_MODULE;

    public static function reset(): void
    {
        self::$active = false;
        self::$name = self::DEFAULT_NAME;
        self::$id = '';
        self::$moduleName = self::DEFAULT_MODULE;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    public static function sessionStatus(): int
    {
        return self::$active
            ? SessionConstants::PHP_SESSION_ACTIVE
            : SessionConstants::PHP_SESSION_NONE;
    }

    public static function assignStatusResult(Variable $dest, ?Context $ctx): void
    {
        // php-src ext/session/session.c returns zend_long (PHP_SESSION_*), not SessionStatus enum (#9262).
        $dest->int(self::sessionStatus());
    }

    public static function enumCaseForStatus(?Context $ctx, int $status): ?Variable
    {
        if (null === $ctx || !isset($ctx->classes['sessionstatus'])) {
            return null;
        }
        $enum = $ctx->classes['sessionstatus'];
        $needle = new Variable(Variable::TYPE_INTEGER);
        $needle->int($status);
        $match = BackedEnum::tryCaseForValue($enum, $needle);
        if (null === $match) {
            return null;
        }
        $canonical = BackedEnum::canonicalCaseVariable($enum, $match->caseName);
        if (null !== $canonical) {
            return $canonical;
        }

        return EnumCaseSupport::createCase($enum, $match->caseName, $match->backingValue);
    }

    public static function getName(): string
    {
        return self::$name;
    }

    public static function getModuleName(): string
    {
        return self::$moduleName;
    }

    public static function isSupportedModule(string $module): bool
    {
        return self::DEFAULT_MODULE === strtolower($module);
    }

    public static function canChangeSaveHandler(?Frame $frame): bool
    {
        if (self::$active) {
            self::triggerSaveHandlerWarning(
                $frame,
                'Session save handler cannot be changed when a session is active'
            );

            return false;
        }
        if (SapiOutput::headersSent()) {
            self::triggerSaveHandlerWarning(
                $frame,
                'Session save handler cannot be changed after headers have already been sent'
            );

            return false;
        }

        return true;
    }

    /**
     * @return string|false previous module name, or false when $module is empty
     */
    public static function setModuleName(string $module)
    {
        $module = strtolower($module);
        if ('' === $module) {
            return false;
        }
        $previous = self::$moduleName;
        self::$moduleName = $module;

        return $previous;
    }

    private static function triggerSaveHandlerWarning(?Frame $frame, string $message): void
    {
        if (null === $frame || null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
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
            $path = SessionFileStorage::storagePath(self::$id);
            if (VmStatPath::isFile($path)) {
                VmFsUnlink::unlink($path);
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
            $oldPath = SessionFileStorage::storagePath($oldId);
            if (VmStatPath::isFile($oldPath)) {
                VmFsUnlink::unlink($oldPath);
            }
        }
        self::$id = self::generateId();
        ResponseContext::addHeader(
            SetcookieLine::build(self::$name, self::$id, 0, '/'),
            false
        );

        return true;
    }

    /**
     * session_abort() — discard in-memory changes and end session (php-src php_session_abort).
     */
    public static function abort(): bool
    {
        if (!self::$active) {
            return false;
        }
        self::$active = false;

        return true;
    }

    /**
     * session_reset() — reload $_SESSION from storage without ending session (php-src php_session_reset).
     */
    public static function reloadFromStorage(Context $ctx): bool
    {
        if (!self::$active) {
            return false;
        }
        self::loadSession($ctx);

        return true;
    }

    /**
     * session_encode() — php handler wire format (php-src php_session_encode).
     *
     * @return string|false
     */
    public static function encode(Context $ctx) {
        if (!self::$active) {
            return false;
        }
        $sessionVar = $ctx->getSuperglobal('_SESSION');
        if (null === $sessionVar || Variable::TYPE_ARRAY !== $sessionVar->type) {
            return VmSessionSerializer::encodePhp($ctx, new HashTable());
        }

        return VmSessionSerializer::encodePhp($ctx, $sessionVar->toArray());
    }

    /** session_decode() — hydrate $_SESSION from php handler blob (php-src php_session_decode). */
    public static function decode(Context $ctx, string $payload): bool
    {
        if (!self::$active) {
            return false;
        }

        return VmSessionSerializer::decodePhp($ctx, $payload);
    }

    /** session_unset() — clear registered session variables (php-src php_session_unset). */
    public static function unsetVariables(Context $ctx): bool
    {
        if (!self::$active) {
            return false;
        }
        $ctx->ensureSuperglobal('_SESSION')->array(new HashTable());

        return true;
    }

    /**
     * session_gc() — purge expired session files via files save handler (php-src php_session_gc).
     *
     * @return int|false number of deleted session files, or false on failure / inactive session
     */
    public static function gc(): int|false
    {
        if (!self::$active) {
            return false;
        }

        return self::gcExpiredFiles();
    }

    /**
     * session_create_id() — collision-resistant id string (php-src php_session_create_id).
     *
     * @return string|false
     */
    public static function createId(?string $prefix = null) {
        if (null !== $prefix && '' !== $prefix) {
            if (\strlen($prefix) > self::MAX_ID_LEN) {
                throw new \ValueError(
                    'session_create_id(): Argument #1 ($prefix) cannot be longer than '
                    .self::MAX_ID_LEN.' characters'
                );
            }
            if ($prefix !== SessionFileStorage::sanitizeId($prefix)) {
                return false;
            }
        }
        $generated = self::generateId();
        if (null === $prefix || '' === $prefix) {
            return $generated;
        }

        return $prefix.$generated;
    }

    /**
     * Scan save path and unlink sess_* files older than gc_maxlifetime (php-src mod_files.c ps_files_cleanup_dir).
     *
     * @return int|false deleted count, or false when directory cannot be opened
     */
    public static function gcExpiredFiles(): int|false
    {
        $dir = SessionFileStorage::storageDir();
        if (!VmStatPath::isDir($dir)) {
            return false;
        }

        $maxLifetime = VmIni::getSessionGcMaxLifetime();
        $entries = VmDir::scandir($dir, \SCANDIR_SORT_NONE);
        if (false === $entries) {
            return false;
        }

        $prefix = SessionFileStorage::PATH_PREFIX;
        $prefixLen = \strlen($prefix);
        $now = time();
        $deleted = 0;

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (\strncmp($entry, $prefix, $prefixLen) !== 0) {
                continue;
            }
            $path = $dir.'/'.$entry;
            $mtime = VmFs::fileMtime($path);
            if (false === $mtime) {
                continue;
            }
            if (($now - $mtime) > $maxLifetime && VmFsUnlink::unlink($path)) {
                ++$deleted;
            }
        }

        return $deleted;
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
        $path = SessionFileStorage::storagePath(self::$id);
        if (!VmStatPath::isFile($path)) {
            $sessionVar->array(new HashTable());

            return;
        }
        $raw = VmFsReadNative::read($path);
        if (false === $raw || '' === $raw) {
            $sessionVar->array(new HashTable());

            return;
        }
        $decoded = VmUnserializeFormat::decodePayload($raw);
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
        $dir = SessionFileStorage::storageDir();
        if (!VmStatPath::isDir($dir)) {
            VmFs::mkdir($dir, 0700, true);
        }
        VmFs::filePutContents(SessionFileStorage::storagePath(self::$id), $payload, \LOCK_EX);
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

    private static function generateId(): string
    {
        $sidLength = 26;
        $bitsPerChar = 5;

        return self::binToReadable(VmString::randomBytes($sidLength), $sidLength, $bitsPerChar);
    }

    /** php-src ext/session/session.c bin_to_readable() — default sid_length=26, sid_bits_per_character=5 (#10864). */
    private static function binToReadable(string $bytes, int $outLength, int $bitsPerChar): string
    {
        static $map = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ,-';
        $out = '';
        $byteLen = \strlen($bytes);
        $p = 0;
        $w = 0;
        $have = 0;
        $mask = (1 << $bitsPerChar) - 1;
        for ($i = 0; $i < $outLength; ++$i) {
            while ($have < $bitsPerChar) {
                if ($p >= $byteLen) {
                    break;
                }
                $w |= (\ord($bytes[$p++]) << $have);
                $have += 8;
            }
            $out .= $map[$w & $mask];
            $w >>= $bitsPerChar;
            $have -= $bitsPerChar;
        }

        return $out;
    }

    private static function sanitizeId(string $id): string
    {
        return SessionFileStorage::sanitizeId($id);
    }
}
