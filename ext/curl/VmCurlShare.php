<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCfg\Func as CfgFunc;

/**
 * CurlShareHandle / CurlSharePersistentHandle lifecycle (php-src ext/curl/share.c; #6322, #20531, #20530).
 *
 * Tracks shared lock-data types without libcurl FFI; full DNS/SSL cache sharing lands in #3325.
 * Persistent handles are keyed by a bitset of CURL_LOCK_DATA_* (php-src curl_share_init_persistent).
 */
final class VmCurlShare
{
    public const CLASS_LC = 'curlsharehandle';

    public const PERSISTENT_CLASS_LC = 'curlsharepersistenthandle';

    /** CURLSHE_OK — php-src SAVE_CURLSH_ERROR / libcurl curl.h */
    public const CURLSHE_OK = 0;

    /** CURLSHE_BAD_OPTION — invalid CURLSHOPT_* */
    public const CURLSHE_BAD_OPTION = 1;

    /** @var array<int, array{closed: bool, shared: array<int, true>, err: int, persistent: bool}> */
    private static array $state = [];

    /** @var array<int, ObjectEntry> persistent_id => reusable handle (process lifetime; #20530) */
    private static array $persistentById = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('CurlShareHandle');
        $entry->isInternal = true;
        // php-src `final class CurlShareHandle` (ext/curl/curl.stub.php; #28371).
        $entry->isFinal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function registerPersistentClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::PERSISTENT_CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('CurlSharePersistentHandle');
        $entry->isInternal = true;
        // php-src `final class CurlSharePersistentHandle` (ext/curl/curl.stub.php; #28371).
        $entry->isFinal = true;
        $arrayProto = new Variable(Variable::TYPE_ARRAY);
        $arrayProto->array(new HashTable());
        $entry->properties[] = new ClassProperty(
            'options',
            null,
            $arrayProto,
            true,
            CfgFunc::FLAG_PUBLIC,
            self::PERSISTENT_CLASS_LC
        );
        $ctx->classes[self::PERSISTENT_CLASS_LC] = $entry;
    }

    public static function init(Context $ctx): Variable
    {
        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'closed' => false,
            'shared' => [],
            'err' => self::CURLSHE_OK,
            'persistent' => false,
        ];
        $var->object($object);

        return $var;
    }

    /**
     * curl_share_init_persistent(array $share_options) — php-src ext/curl/share.c (#20530).
     *
     * @param list<int> $sortedLockData de-duplicated CURL_LOCK_DATA_* in DNS→SSL→CONNECT→PSL order
     */
    public static function initPersistent(Context $ctx, array $sortedLockData, int $persistentId): Variable
    {
        self::registerPersistentClass($ctx);
        if (isset(self::$persistentById[$persistentId])) {
            $existing = self::$persistentById[$persistentId];
            $var = new Variable(Variable::TYPE_OBJECT);
            $var->object($existing);

            return $var;
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::PERSISTENT_CLASS_LC]);
        $shared = [];
        $ht = new HashTable();
        foreach ($sortedLockData as $lockData) {
            $slot = new Variable(Variable::TYPE_INTEGER);
            $slot->int($lockData);
            $ht->append($slot);
            $shared[$lockData] = true;
        }
        // First write to readonly $options before marking constructed (php-src zend_update_property).
        $object->getProperty('options')->array($ht);
        $object->constructed = true;
        self::$state[$object->id] = [
            'closed' => false,
            'shared' => $shared,
            'err' => self::CURLSHE_OK,
            'persistent' => true,
        ];
        self::$persistentById[$persistentId] = $object;
        $var->object($object);

        return $var;
    }

    /**
     * Parse + validate $share_options for curl_share_init_persistent (php-src share.c).
     *
     * @return array{0: list<int>, 1: int} sorted lock-data list + persistent bit id
     *
     * @throws \TypeError|\ValueError|\ArgumentCountError
     */
    public static function parsePersistentShareOptions(Variable $optionsVar): array
    {
        $optionsVar = $optionsVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $optionsVar->type) {
            throw new \TypeError(\sprintf(
                'curl_share_init_persistent(): Argument #1 ($share_options) must be of type array, %s given',
                VmStreamArg::debugTypeName($optionsVar)
            ));
        }
        $ht = $optionsVar->toArray();
        if (0 === $ht->getNumElements()) {
            throw new \ValueError(
                'curl_share_init_persistent(): Argument #1 ($share_options) must not be empty'
            );
        }

        $persistentId = 0;
        foreach ($ht->iterateKeyed(true) as [, $entry]) {
            $entry = $entry->resolveIndirect();
            $option = self::tryGetLongShareOption($entry);
            if (null === $option) {
                throw new \TypeError(\sprintf(
                    'curl_share_init_persistent(): Argument #1 ($share_options) must contain only int values, %s given',
                    VmStreamArg::debugTypeName($entry)
                ));
            }
            match ($option) {
                CurlConstants::CURL_LOCK_DATA_COOKIE => throw new \ValueError(
                    'curl_share_init_persistent(): Argument #1 ($share_options) must not contain CURL_LOCK_DATA_COOKIE because sharing cookies across PHP requests is unsafe'
                ),
                CurlConstants::CURL_LOCK_DATA_DNS => $persistentId |= 1 << 0,
                CurlConstants::CURL_LOCK_DATA_SSL_SESSION => $persistentId |= 1 << 1,
                CurlConstants::CURL_LOCK_DATA_CONNECT => $persistentId |= 1 << 2,
                CurlConstants::CURL_LOCK_DATA_PSL => $persistentId |= 1 << 3,
                default => throw new \ValueError(
                    'curl_share_init_persistent(): Argument #1 ($share_options) must contain only CURL_LOCK_DATA_* constants'
                ),
            };
        }

        $sorted = [];
        if ($persistentId & (1 << 0)) {
            $sorted[] = CurlConstants::CURL_LOCK_DATA_DNS;
        }
        if ($persistentId & (1 << 1)) {
            $sorted[] = CurlConstants::CURL_LOCK_DATA_SSL_SESSION;
        }
        if ($persistentId & (1 << 2)) {
            $sorted[] = CurlConstants::CURL_LOCK_DATA_CONNECT;
        }
        if ($persistentId & (1 << 3)) {
            $sorted[] = CurlConstants::CURL_LOCK_DATA_PSL;
        }

        return [$sorted, $persistentId];
    }

    /** zval_try_get_long — int / numeric string / bool / float; else null (php-src share.c). */
    private static function tryGetLongShareOption(Variable $entry): ?int
    {
        if (Variable::TYPE_INTEGER === $entry->type) {
            return $entry->toInt();
        }
        if (Variable::TYPE_FLOAT === $entry->type) {
            return (int) $entry->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $entry->type) {
            return $entry->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $entry->type) {
            $s = $entry->toString();
            if ('' === $s || !\is_numeric($s)) {
                return null;
            }

            return (int) $s;
        }

        return null;
    }

    public static function setopt(ObjectEntry $share, int $option, Variable $value, Frame $frame): bool
    {
        self::ensureLive($share);
        $lockData = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'curl_share_setopt', 3, 'value');

        return match ($option) {
            CurlConstants::CURLSHOPT_SHARE => self::finishSetopt($share, self::share($share, $lockData)),
            CurlConstants::CURLSHOPT_UNSHARE => self::finishSetopt($share, self::unshare($share, $lockData)),
            default => self::badOption($share),
        };
    }

    /**
     * Last share error — php-src curl_share_errno() / SAVE_CURLSH_ERROR (#20531).
     */
    public static function errno(ObjectEntry $share): int
    {
        if (!isset(self::$state[$share->id])) {
            return self::CURLSHE_OK;
        }

        return self::$state[$share->id]['err'];
    }

    public static function close(ObjectEntry $share): void
    {
        if (!isset(self::$state[$share->id])) {
            return;
        }
        self::$state[$share->id]['closed'] = true;
        unset(self::$state[$share->id]);
    }

    public static function isShareObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function isPersistentShareObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::PERSISTENT_CLASS_LC === strtolower($object->class->name);
    }

    /** CurlShareHandle or CurlSharePersistentHandle — CURLOPT_SHARE accepts both (php-src #20530). */
    public static function isShareableObject(?ObjectEntry $object): bool
    {
        return self::isShareObject($object) || self::isPersistentShareObject($object);
    }

    public static function isLiveShareObject(ObjectEntry $object): bool
    {
        return self::isShareableObject($object) && isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    public static function attachToEasy(ObjectEntry $share): void
    {
        if (!self::isShareableObject($share)) {
            throw new \TypeError('curl_setopt(): Argument #3 ($value) must be of type CurlShareHandle');
        }
        if (!isset(self::$state[$share->id])) {
            return;
        }
    }

    private static function share(ObjectEntry $share, int $lockData): int
    {
        self::$state[$share->id]['shared'][$lockData] = true;

        return self::CURLSHE_OK;
    }

    private static function unshare(ObjectEntry $share, int $lockData): int
    {
        // libcurl rejects CURLSHOPT_UNSHARE + CURL_LOCK_DATA_PSL (CURLSHE_BAD_OPTION);
        // Zend surfaces false + "Unknown share option" (#27704, php-src share.c / curl.h).
        if (CurlConstants::CURL_LOCK_DATA_PSL === $lockData) {
            return self::CURLSHE_BAD_OPTION;
        }
        unset(self::$state[$share->id]['shared'][$lockData]);

        return self::CURLSHE_OK;
    }

    private static function finishSetopt(ObjectEntry $share, int $error): bool
    {
        self::saveError($share, $error);

        return self::CURLSHE_OK === $error;
    }

    /** @throws \ValueError */
    private static function badOption(ObjectEntry $share): never
    {
        // php-src share.c: SAVE_CURLSH_ERROR after zend_argument_value_error path (#20531)
        self::saveError($share, self::CURLSHE_BAD_OPTION);
        throw new \ValueError('curl_share_setopt(): Argument #2 ($option) is not a valid cURL share option');
    }

    private static function saveError(ObjectEntry $share, int $error): void
    {
        if (isset(self::$state[$share->id])) {
            self::$state[$share->id]['err'] = $error;
        }
    }

    private static function ensureLive(ObjectEntry $share): void
    {
        if (!self::isShareObject($share)) {
            throw new \TypeError('curl_share_setopt(): Argument #1 ($share_handle) must be of type CurlShareHandle');
        }
        if (!isset(self::$state[$share->id])) {
            return;
        }
    }
}
