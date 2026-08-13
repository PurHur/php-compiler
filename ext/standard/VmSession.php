<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\session\SessionConstants;
use PHPCompiler\ext\session\SessionFileStorage;
use PHPCompiler\ext\session\SessionHandlerBuiltin;
use PHPCompiler\ext\session\SessionUserHandler;
use PHPCompiler\Frame;
use PHPCompiler\VM;
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

    public const EMPTY_NAME_WARNING = 'session.name "%s" cannot be numeric or empty';

    public const ACTIVE_ID_CHANGE_WARNING = 'session_id(): Session ID cannot be changed when a session is active';

    public const HEADERS_SENT_ID_CHANGE_WARNING = 'session_id(): Session ID cannot be changed after headers have already been sent';

    public const ACTIVE_NAME_CHANGE_WARNING = 'session_name(): Session name cannot be changed when a session is active';

    public const HEADERS_SENT_NAME_CHANGE_WARNING = 'session_name(): Session name cannot be changed after headers have already been sent';

    public const DEFAULT_MODULE = 'files';

    public const MAX_MODULE_LEN = 32;

    /** php-src ext/session/session.c — PS(cache_expire) / session.cache_expire default (minutes). */
    public const DEFAULT_CACHE_EXPIRE = 180;

    /** php-src ext/session/session.c — PS(cache_limiter) / session.cache_limiter default. */
    public const DEFAULT_CACHE_LIMITER = 'nocache';

    public const ACTIVE_CACHE_LIMITER_WARNING = 'Session cache limiter cannot be changed when a session is active';

    public const HEADERS_SENT_CACHE_LIMITER_WARNING = 'Session cache limiter cannot be changed after headers have already been sent';

    public const ACTIVE_COOKIE_PARAMS_WARNING = 'Session cookie parameters cannot be changed when a session is active';

    public const HEADERS_SENT_COOKIE_PARAMS_WARNING = 'Session cookie parameters cannot be changed after headers have already been sent';

    public const ACTIVE_SAVE_PATH_WARNING = 'Session save path cannot be changed when a session is active';

    public const HEADERS_SENT_SAVE_PATH_WARNING = 'Session save path cannot be changed after headers have already been sent';

    /** php-src ext/session/session.c — php_session_start() when SG(headers_sent). */
    public const HEADERS_SENT_START_WARNING = 'session_start(): Session cannot be started after headers have already been sent';

    /** php-src ext/session/session.c — PG(session_save_path) default on Linux CLI. */
    public const DEFAULT_SAVE_PATH = '/var/lib/php/sessions';

    private static bool $active = false;

    private static string $name = self::DEFAULT_NAME;

    private static string $id = '';

    private static string $moduleName = self::DEFAULT_MODULE;

    private static int $cacheExpire = self::DEFAULT_CACHE_EXPIRE;

    private static string $cacheLimiter = self::DEFAULT_CACHE_LIMITER;

    private static int $cookieLifetime = 0;

    private static string $cookiePath = '/';

    private static string $cookieDomain = '';

    private static bool $cookieSecure = false;

    private static bool $cookieHttponly = false;

    private static string $cookieSamesite = '';

    private static bool $useStrictMode = false;

    /** php-src session.lazy_write — default On (ext/session/session.c; #21156). */
    private static bool $lazyWrite = true;

    /** Encoded payload from last load/save — dirty detection for lazy_write. */
    private static ?string $loadedPayload = null;

    public static function reset(): void
    {
        self::$active = false;
        self::$name = self::DEFAULT_NAME;
        self::$id = '';
        self::$moduleName = self::DEFAULT_MODULE;
        self::$cacheExpire = self::DEFAULT_CACHE_EXPIRE;
        self::$cacheLimiter = self::DEFAULT_CACHE_LIMITER;
        self::resetCookieParams();
        self::$useStrictMode = false;
        self::$lazyWrite = true;
        self::$loadedPayload = null;
        SessionUserHandler::reset();
        SessionHandlerBuiltin::reset();
    }

    public static function resetCookieParams(): void
    {
        self::$cookieLifetime = 0;
        self::$cookiePath = '/';
        self::$cookieDomain = '';
        self::$cookieSecure = false;
        self::$cookieHttponly = false;
        self::$cookieSamesite = '';
    }

    /**
     * @return array{
     *     lifetime: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     * }
     */
    public static function getCookieParams(): array
    {
        return [
            'lifetime' => self::$cookieLifetime,
            'path' => self::$cookiePath,
            'domain' => self::$cookieDomain,
            'secure' => self::$cookieSecure,
            'httponly' => self::$cookieHttponly,
            'samesite' => self::$cookieSamesite,
        ];
    }

    public static function cookieParamsHashTable(): HashTable
    {
        $ht = new HashTable();
        $params = self::getCookieParams();
        $lifetime = new Variable(Variable::TYPE_INTEGER);
        $lifetime->int($params['lifetime']);
        $ht->add('lifetime', $lifetime);
        $path = new Variable(Variable::TYPE_STRING);
        $path->string($params['path']);
        $ht->add('path', $path);
        $domain = new Variable(Variable::TYPE_STRING);
        $domain->string($params['domain']);
        $ht->add('domain', $domain);
        $secure = new Variable(Variable::TYPE_BOOLEAN);
        $secure->bool($params['secure']);
        $ht->add('secure', $secure);
        $httponly = new Variable(Variable::TYPE_BOOLEAN);
        $httponly->bool($params['httponly']);
        $ht->add('httponly', $httponly);
        $samesite = new Variable(Variable::TYPE_STRING);
        $samesite->string($params['samesite']);
        $ht->add('samesite', $samesite);

        return $ht;
    }

    /**
     * @param array{
     *     lifetime: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     * } $params
     */
    public static function applyCookieParams(?Frame $frame, array $params): bool
    {
        if (!self::canChangeCookieParams($frame)) {
            return false;
        }
        self::forceApplyCookieParams($params);

        return true;
    }

    /**
     * Compile-time / thin-AOT apply — skips SAPI headersSent of the compiler process (#30758).
     *
     * @param array{
     *     lifetime: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     * } $params
     */
    public static function forceApplyCookieParams(array $params): void
    {
        if ($params['lifetime'] < 0) {
            throw new \ValueError(
                'session_set_cookie_params(): Argument #1 ($lifetime_or_options) must be greater than or equal to 0'
            );
        }
        self::$cookieLifetime = $params['lifetime'];
        self::$cookiePath = $params['path'];
        self::$cookieDomain = $params['domain'];
        self::$cookieSecure = $params['secure'];
        self::$cookieHttponly = $params['httponly'];
        self::$cookieSamesite = $params['samesite'];
    }

    public static function canChangeCookieParams(?Frame $frame): bool
    {
        if (self::$active) {
            self::triggerSessionWarning($frame, self::ACTIVE_COOKIE_PARAMS_WARNING);

            return false;
        }
        if (SapiOutput::headersSent()) {
            self::triggerSessionWarning($frame, self::HEADERS_SENT_COOKIE_PARAMS_WARNING);

            return false;
        }

        return true;
    }

    public static function getCacheExpire(): int
    {
        return self::$cacheExpire;
    }

    public static function setCacheExpire(int $minutes): void
    {
        if ($minutes < 0) {
            throw new \ValueError(
                'session_cache_expire(): Argument #1 ($value) must be greater than or equal to 0'
            );
        }
        self::$cacheExpire = $minutes;
    }

    public static function getCacheLimiter(): string
    {
        return self::$cacheLimiter;
    }

    /**
     * @return string|false previous limiter on success, or false when change is rejected
     */
    public static function setCacheLimiter(?Frame $frame, string $newLimiter): string|false
    {
        if (!self::canChangeCacheLimiter($frame)) {
            return false;
        }

        return self::forceSetCacheLimiter($newLimiter);
    }

    /** Compile-time / thin-AOT set — skips compiler-process headersSent (#30758). */
    public static function forceSetCacheLimiter(string $newLimiter): string
    {
        $previous = self::$cacheLimiter;
        self::$cacheLimiter = $newLimiter;

        return $previous;
    }

    public static function canChangeCacheLimiter(?Frame $frame): bool
    {
        if (self::$active) {
            self::triggerSessionWarning($frame, self::ACTIVE_CACHE_LIMITER_WARNING);

            return false;
        }
        if (SapiOutput::headersSent()) {
            self::triggerSessionWarning($frame, self::HEADERS_SENT_CACHE_LIMITER_WARNING);

            return false;
        }

        return true;
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

    public static function getSavePath(): string
    {
        return VmIni::getSessionSavePath();
    }

    public static function canChangeSavePath(?Frame $frame): bool
    {
        if (self::$active) {
            self::triggerSessionWarning($frame, self::ACTIVE_SAVE_PATH_WARNING);

            return false;
        }
        if (SapiOutput::headersSent()) {
            self::triggerSessionWarning($frame, self::HEADERS_SENT_SAVE_PATH_WARNING);

            return false;
        }

        return true;
    }

    /**
     * @return string|false previous save path on success, or false when change is rejected
     */
    public static function setSavePath(?Frame $frame, string $newPath): string|false
    {
        if (!self::canChangeSavePath($frame)) {
            return false;
        }

        return self::forceSetSavePath($newPath);
    }

    /** Compile-time / thin-AOT set — skips compiler-process headersSent (#30758). */
    public static function forceSetSavePath(string $newPath): string
    {
        return VmIni::setSessionSavePathValue($newPath);
    }

    public static function isSupportedModule(string $module): bool
    {
        $module = strtolower($module);
        if ('user' === $module) {
            return SessionUserHandler::hasHandler();
        }

        return self::DEFAULT_MODULE === $module;
    }

    public static function canChangeSaveHandler(?Frame $frame): bool
    {
        if (self::$active) {
            self::triggerSessionWarning(
                $frame,
                'Session save handler cannot be changed when a session is active'
            );

            return false;
        }
        if (SapiOutput::headersSent()) {
            self::triggerSessionWarning(
                $frame,
                'Session save handler cannot be changed after headers have already been sent'
            );

            return false;
        }

        return true;
    }

    public static function canChangeName(?Frame $frame): bool
    {
        if (self::$active) {
            self::triggerSessionWarning($frame, self::ACTIVE_NAME_CHANGE_WARNING);

            return false;
        }
        if (SapiOutput::headersSent()) {
            self::triggerSessionWarning($frame, self::HEADERS_SENT_NAME_CHANGE_WARNING);

            return false;
        }

        return true;
    }

    public static function canChangeId(?Frame $frame): bool
    {
        if (self::$active) {
            self::triggerSessionWarning($frame, self::ACTIVE_ID_CHANGE_WARNING);

            return false;
        }
        if (SapiOutput::headersSent()) {
            self::triggerSessionWarning($frame, self::HEADERS_SENT_ID_CHANGE_WARNING);

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

    private static function triggerSessionWarning(?Frame $frame, string $message): void
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
     * php-src: ext/session/session.c — session.name must be non-empty and non-numeric.
     */
    public static function isRejectedSessionName(string $name): bool
    {
        return '' === $name || is_numeric($name);
    }

    public static function rejectedSessionNameMessage(string $name): string
    {
        return \sprintf(self::EMPTY_NAME_WARNING, $name);
    }

    /**
     * @return string|false
     * previous name, or false when session is active
     */
    public static function setName(string $name) {
        if (self::isRejectedSessionName($name)) {
            return self::$name;
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
     * previous id, or false when $id is invalid (non-empty but sanitizes to empty)
     */
    public static function setId(string $id) {
        if ('' === $id) {
            $previous = self::$id;
            self::$id = '';

            return $previous;
        }
        $sanitized = self::sanitizeId($id);
        if ('' === $sanitized) {
            return false;
        }
        $previous = self::$id;
        self::$id = $sanitized;

        return $previous;
    }

    public static function setUseStrictMode(bool $enabled): void
    {
        self::$useStrictMode = $enabled;
    }

    public static function isUseStrictMode(): bool
    {
        return self::$useStrictMode;
    }

    public static function setLazyWrite(bool $enabled): void
    {
        self::$lazyWrite = $enabled;
    }

    public static function isLazyWrite(): bool
    {
        return self::$lazyWrite;
    }

    /** php-src session_start read_and_close — close without persisting after read (#18457). */
    public static function readClose(): void
    {
        self::$active = false;
    }

    public static function start(Context $ctx): bool
    {
        if (self::$active) {
            return false;
        }
        $incomingId = self::readCookieId($ctx);
        if ('' !== $incomingId) {
            if (self::$useStrictMode && !self::sessionIdExists($incomingId)) {
                self::$id = self::generateId();
                ResponseContext::addHeader(
                    self::buildSessionSetCookieLine(self::$id),
                    false
                );
            } else {
                self::$id = $incomingId;
            }
        } elseif ('' === self::$id) {
            // php-src ext/session/session.c — reuse PS(id) after session_write_close() in-request.
            self::$id = self::generateId();
            ResponseContext::addHeader(
                self::buildSessionSetCookieLine(self::$id),
                false
            );
        } elseif (self::$useStrictMode && !self::sessionIdExists(self::$id)) {
            // php-src: PS(id) from session_id() also runs s_validate_sid under use_strict_mode (#21155).
            self::$id = self::generateId();
            ResponseContext::addHeader(
                self::buildSessionSetCookieLine(self::$id),
                false
            );
        }
        // php-src php_session_start() marks status active before save-handler open
        // (SessionHandler::open requires php_session_active — #19246 / mod_user_class.c).
        self::$active = true;
        if (SessionUserHandler::isActiveModule() && !SessionUserHandler::open($ctx)) {
            self::$active = false;

            return false;
        }
        self::loadSession($ctx);

        return true;
    }

    public static function writeClose(Context $ctx): bool
    {
        if (!self::$active) {
            return false;
        }
        self::saveSession($ctx);
        if (SessionUserHandler::isActiveModule()) {
            SessionUserHandler::close($ctx);
        }
        self::$active = false;

        return true;
    }

    public static function destroy(Context $ctx): bool
    {
        if (!self::$active) {
            return false;
        }
        if ('' !== self::$id) {
            if (SessionUserHandler::isActiveModule()) {
                SessionUserHandler::destroy($ctx, self::$id);
            } else {
                $path = SessionFileStorage::storagePath(self::$id);
                if (VmStatPath::isFile($path)) {
                    VmFsUnlink::unlink($path);
                }
            }
        }
        $ctx->ensureSuperglobal('_SESSION')->array(new HashTable());
        self::$active = false;
        self::$id = '';
        self::$loadedPayload = null;

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
            self::buildSessionSetCookieLine(self::$id),
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
            return VmSessionSerializer::encode($ctx, new HashTable());
        }

        return VmSessionSerializer::encode($ctx, $sessionVar->toArray());
    }

    /** session_decode() — hydrate $_SESSION from php handler blob (php-src php_session_decode). */
    public static function decode(Context $ctx, string $payload): bool
    {
        if (!self::$active) {
            return false;
        }

        return VmSessionSerializer::decode($ctx, $payload);
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

        if (SessionUserHandler::isActiveModule()) {
            $vm = VM::running();
            if (null === $vm) {
                return false;
            }

            return SessionUserHandler::gc($vm->context, VmIni::getSessionGcMaxlifetime());
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
    public static function gcExpiredFiles(?int $maxLifetime = null): int|false
    {
        $dir = SessionFileStorage::storageDir();
        if (!VmStatPath::isDir($dir)) {
            return false;
        }

        $maxLifetime ??= VmIni::getSessionGcMaxLifetime();
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

    private static function buildSessionSetCookieLine(string $sessionId): string
    {
        $expires = 0;
        if (self::$cookieLifetime > 0) {
            $expires = time() + self::$cookieLifetime;
        }

        return SetcookieLine::build(
            self::$name,
            $sessionId,
            $expires,
            self::$cookiePath,
            self::$cookieDomain,
            self::$cookieSecure,
            self::$cookieHttponly,
            self::$cookieSamesite
        );
    }

    private static function sessionIdExists(string $id): bool
    {
        if ('' === $id) {
            return false;
        }
        if (SessionUserHandler::isActiveModule()) {
            $vm = VM::running();
            if (null === $vm) {
                return false;
            }

            return SessionUserHandler::validateId($vm->context, $id);
        }

        return VmStatPath::isFile(SessionFileStorage::storagePath($id));
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
        // php_session_track_init — destroy then decode merges into empty (#26088).
        $sessionVar->array(new HashTable());
        if ('' === self::$id) {
            self::$loadedPayload = '';

            return;
        }
        if (SessionUserHandler::isActiveModule()) {
            $raw = SessionUserHandler::read($ctx, self::$id);
            if ('' === $raw || !VmSessionSerializer::decode($ctx, $raw)) {
                self::$loadedPayload = '';

                return;
            }
            self::$loadedPayload = $raw;

            return;
        }
        $path = SessionFileStorage::storagePath(self::$id);
        if (!VmStatPath::isFile($path)) {
            self::$loadedPayload = '';

            return;
        }
        $raw = VmFsReadNative::read($path);
        if (false === $raw || '' === $raw) {
            self::$loadedPayload = '';

            return;
        }
        if (!VmSessionSerializer::decode($ctx, $raw)) {
            self::$loadedPayload = '';

            return;
        }
        self::$loadedPayload = $raw;
    }

    private static function saveSession(Context $ctx): void
    {
        if ('' === self::$id) {
            return;
        }
        $sessionVar = $ctx->getSuperglobal('_SESSION');
        if (null === $sessionVar || Variable::TYPE_ARRAY !== $sessionVar->type) {
            return;
        }
        $payload = VmSessionSerializer::encode($ctx, $sessionVar->toArray());
        if (false === $payload) {
            return;
        }
        if (SessionUserHandler::isActiveModule()) {
            // php-src PS(lazy_write): unchanged payload → update_timestamp when implemented (#21156).
            if (self::$lazyWrite
                && SessionUserHandler::hasUpdateTimestamp()
                && null !== self::$loadedPayload
                && $payload === self::$loadedPayload
            ) {
                SessionUserHandler::updateTimestamp($ctx, self::$id, $payload);
            } else {
                SessionUserHandler::write($ctx, self::$id, $payload);
            }
            self::$loadedPayload = $payload;

            return;
        }
        $dir = SessionFileStorage::storageDir();
        if (!VmStatPath::isDir($dir)) {
            VmFs::mkdir($dir, 0700, true);
        }
        VmFs::filePutContents(SessionFileStorage::storagePath(self::$id), $payload, \LOCK_EX);
        self::$loadedPayload = $payload;
    }

    private static function generateId(): string
    {
        if (SessionUserHandler::isActiveModule()) {
            $vm = VM::running();
            if (null !== $vm) {
                $custom = SessionUserHandler::createSid($vm->context);
                if (null !== $custom && '' !== $custom) {
                    return self::sanitizeId($custom);
                }
            }
        }
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
