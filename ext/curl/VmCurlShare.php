<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;

/**
 * CurlShareHandle lifecycle — PHP-owned share pool (php-src ext/curl/share.c; #6322, #20531).
 *
 * Tracks shared lock-data types without libcurl FFI; full DNS/SSL cache sharing lands in #3325.
 */
final class VmCurlShare
{
    public const CLASS_LC = 'curlsharehandle';

    /** CURLSHE_OK — php-src SAVE_CURLSH_ERROR / libcurl curl.h */
    public const CURLSHE_OK = 0;

    /** CURLSHE_BAD_OPTION — invalid CURLSHOPT_* */
    public const CURLSHE_BAD_OPTION = 1;

    /** @var array<int, array{closed: bool, shared: array<int, true>, err: int}> */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('CurlShareHandle');
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function init(Context $ctx): Variable
    {
        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = ['closed' => false, 'shared' => [], 'err' => self::CURLSHE_OK];
        $var->object($object);

        return $var;
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

    public static function isLiveShareObject(ObjectEntry $object): bool
    {
        return self::isShareObject($object) && isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    public static function attachToEasy(ObjectEntry $share): void
    {
        self::ensureLive($share);
    }

    private static function share(ObjectEntry $share, int $lockData): int
    {
        self::$state[$share->id]['shared'][$lockData] = true;

        return self::CURLSHE_OK;
    }

    private static function unshare(ObjectEntry $share, int $lockData): int
    {
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
