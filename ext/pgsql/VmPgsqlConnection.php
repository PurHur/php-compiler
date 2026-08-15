<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * PgSql\Connection object + connection state (php-src ext/pgsql; #3741).
 */
final class VmPgsqlConnection
{
    public const CLASS_LC = 'pgsql\\connection';

    public const CLASS_NAME = 'PgSql\\Connection';

    /** @var array<int, array{native: \FFI\CData, closed: bool, trace_fp: ?\FFI\CData, object: ObjectEntry, notices: list<string>, notice_cb: ?callable, persistent: bool}> */
    private static array $state = [];

    /**
     * Process-local persistent PGconn* pool (php-src EG(persistent_list); #22218).
     *
     * @var array<string, \FFI\CData>
     */
    private static array $persistentPool = [];

    private static string $lastError = '';

    /** Default link id for optional-connection builtins (php-src FETCH_DEFAULT_LINK; #20574). */
    private static ?int $defaultLinkId = null;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function setLastError(string $message): void
    {
        self::$lastError = $message;
    }

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function wrap(\FFI\CData $native, Context $ctx, bool $persistent = false): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'native' => $native,
            'closed' => false,
            'trace_fp' => null,
            'object' => $object,
            'notices' => [],
            'notice_cb' => null,
            'persistent' => $persistent,
        ];
        self::$defaultLinkId = $object->id;
        VmPgsqlNative::installNoticeProcessor($native, $object->id);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    /** php-src hash key: "pgsql" + conninfo + "_" + (flags & ~FORCE_NEW) (#22218). */
    public static function persistentHash(string $conninfo, int $flags): string
    {
        return 'pgsql'.$conninfo.'_'.($flags & ~PgsqlConstants::PGSQL_CONNECT_FORCE_NEW);
    }

    public static function persistentPoolGet(string $hash): ?\FFI\CData
    {
        return self::$persistentPool[$hash] ?? null;
    }

    public static function persistentPoolSet(string $hash, \FFI\CData $native): void
    {
        self::$persistentPool[$hash] = $native;
    }

    /**
     * Keep the FFI notice-processor closure alive for the connection lifetime (#22217).
     */
    public static function setNoticeCallback(int $objectId, callable $cb): void
    {
        if (!isset(self::$state[$objectId])) {
            return;
        }
        self::$state[$objectId]['notice_cb'] = $cb;
    }

    /**
     * Append a trimmed NOTICE message (php-src _php_pgsql_notice_handler; #22217).
     */
    public static function appendNotice(int $objectId, string $message): void
    {
        if (!isset(self::$state[$objectId]) || self::$state[$objectId]['closed']) {
            return;
        }
        self::$state[$objectId]['notices'][] = self::trimNoticeMessage($message);
    }

    /**
     * pg_last_notice modes (php-src; #22217).
     *
     * @return string|HashTable|bool
     */
    public static function lastNotice(ObjectEntry $object, int $mode): string|HashTable|bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            throw new \TypeError('pg_*(): supplied resource is not a valid PostgreSQL link resource');
        }
        $notices = &self::$state[$object->id]['notices'];
        switch ($mode) {
            case PgsqlConstants::PGSQL_NOTICE_LAST:
                if ([] === $notices) {
                    return '';
                }

                return $notices[\count($notices) - 1];
            case PgsqlConstants::PGSQL_NOTICE_ALL:
                $ht = new HashTable();
                foreach ($notices as $i => $msg) {
                    $slot = new Variable();
                    $slot->string($msg);
                    $ht->add((string) $i, $slot);
                }

                return $ht;
            case PgsqlConstants::PGSQL_NOTICE_CLEAR:
                $notices = [];

                return true;
            default:
                throw new \ValueError(
                    'pg_last_notice(): Argument #2 ($mode) must be one of PGSQL_NOTICE_LAST, PGSQL_NOTICE_ALL, or PGSQL_NOTICE_CLEAR'
                );
        }
    }

    /** php-src _php_pgsql_trim_message. */
    public static function trimNoticeMessage(string $message): string
    {
        $i = \strlen($message);
        if ($i > 2 && ('.' === $message[$i - 1]) && ("\r" === $message[$i - 2] || "\n" === $message[$i - 2])) {
            --$i;
        }
        while ($i > 1 && ("\r" === $message[$i - 1] || "\n" === $message[$i - 1])) {
            --$i;
        }

        return \substr($message, 0, $i);
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    public static function native(ObjectEntry $object): \FFI\CData
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            throw new \TypeError('pg_*(): supplied resource is not a valid PostgreSQL link resource');
        }

        return self::$state[$object->id]['native'];
    }

    /**
     * Resolve optional connection arg or default link (php-src FETCH_DEFAULT_LINK; #20574).
     */
    public static function resolveOptionalConnection(?ObjectEntry $provided): ?ObjectEntry
    {
        if (null !== $provided) {
            return $provided;
        }
        if (null === self::$defaultLinkId) {
            return null;
        }
        $row = self::$state[self::$defaultLinkId] ?? null;
        if (null === $row || $row['closed']) {
            return null;
        }

        return $row['object'];
    }

    /**
     * 1-arg form: emit E_DEPRECATED then return default link (may be null).
     *
     * php-src FETCH_DEFAULT_LINK() always documents the deprecation when the connection
     * argument is omitted (#31184 / ext/pgsql/pgsql.c).
     */
    public static function fetchDefaultLinkDeprecated(?Frame $frame, string $functionName): ?ObjectEntry
    {
        VmPgsqlDefaultLinkDeprecation::emit($frame, $functionName);

        return self::resolveOptionalConnection(null);
    }

    /**
     * Explicit connection, or FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31220).
     *
     * When {@see $provided} is null, emits E_DEPRECATED then throws {@see \Error} if no default link.
     */
    public static function connectionOrDefaultDeprecated(
        ?ObjectEntry $provided,
        ?Frame $frame,
        string $functionName
    ): ObjectEntry {
        if (null !== $provided) {
            return $provided;
        }
        $conn = self::fetchDefaultLinkDeprecated($frame, $functionName);
        if (null === $conn) {
            throw new \Error('No PostgreSQL connection opened yet');
        }

        return $conn;
    }

    /**
     * Optional connection or default link; warn when none (php-src CHECK_DEFAULT_LINK; #20680).
     *
     * Prefer {@see connectionOrDefaultDeprecated()} for FETCH_DEFAULT_LINK call sites (#31220).
     */
    public static function requireOptionalOrDefault(?ObjectEntry $provided, string $functionName): ?ObjectEntry
    {
        $connObj = self::resolveOptionalConnection($provided);
        if (null === $connObj) {
            @\trigger_error($functionName.'(): No PostgreSQL connection opened yet', \E_USER_WARNING);
        }

        return $connObj;
    }

    public static function setTraceFp(ObjectEntry $object, ?\FFI\CData $fp): void
    {
        if (!isset(self::$state[$object->id])) {
            return;
        }
        $prev = self::$state[$object->id]['trace_fp'];
        if (null !== $prev && $prev !== $fp) {
            VmPgsqlNative::fclose($prev);
        }
        self::$state[$object->id]['trace_fp'] = $fp;
    }

    public static function clearTraceFp(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id])) {
            return;
        }
        $fp = self::$state[$object->id]['trace_fp'];
        if (null !== $fp) {
            VmPgsqlNative::fclose($fp);
            self::$state[$object->id]['trace_fp'] = null;
        }
    }

    public static function close(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        self::clearTraceFp($object);
        $persistent = self::$state[$object->id]['persistent'];
        $native = self::$state[$object->id]['native'];
        // Persistent: keep PGconn* in the process pool (php-src pgsql_link_free; #22218).
        if (!$persistent) {
            VmPgsqlNative::clearNoticeProcessor($native);
            VmPgsqlNative::finish($native);
        }
        self::$state[$object->id]['closed'] = true;
        self::$state[$object->id]['notice_cb'] = null;
        self::$state[$object->id]['notices'] = [];
        if (self::$defaultLinkId === $object->id) {
            self::$defaultLinkId = null;
        }

        return true;
    }
}
