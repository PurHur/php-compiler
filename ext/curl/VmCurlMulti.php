<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;

/**
 * CurlMultiHandle multi API — libcurl FFI via {@see VmCurlNative}
 * (php-src ext/curl/multi.c; #3721).
 */
final class VmCurlMulti
{
    public const CLASS_LC = 'curlmultihandle';

    /**
     * @var array<int, array{
     *   closed: bool,
     *   native: ?\FFI\CData,
     *   errno: int,
     *   easy_ids: array<int, ObjectEntry>
     * }>
     */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('CurlMultiHandle');
        $entry->isInternal = true;
        // php-src `final class CurlMultiHandle` (ext/curl/curl.stub.php; #28371).
        $entry->isFinal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function init(Context $ctx): Variable
    {
        self::registerClass($ctx);
        if (!VmCurlNative::available()) {
            throw new \LogicException('curl_multi_init() requires libcurl FFI (issue #3721)');
        }
        $native = VmCurlNative::multiInit();
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'closed' => false,
            'native' => $native,
            'errno' => CurlConstants::CURLM_OK,
            'easy_ids' => [],
        ];
        $var->object($object);

        return $var;
    }

    public static function addHandle(ObjectEntry $multi, ObjectEntry $easy): int
    {
        self::ensureLive($multi, 'curl_multi_add_handle');
        VmCurlEasy::ensureLivePublic($easy, 'curl_multi_add_handle');
        if (null !== VmCurlEasy::multiIdForEasy($easy)) {
            self::$state[$multi->id]['errno'] = CurlConstants::CURLM_ADDED_ALREADY;

            return CurlConstants::CURLM_ADDED_ALREADY;
        }
        $mh = self::$state[$multi->id]['native'];
        $ch = VmCurlEasy::nativeHandle($easy);
        if (null === $mh || null === $ch) {
            self::$state[$multi->id]['errno'] = CurlConstants::CURLM_BAD_EASY_HANDLE;

            return CurlConstants::CURLM_BAD_EASY_HANDLE;
        }
        VmCurlEasy::prepareMultiTransfer($easy);
        $rc = VmCurlNative::multiAddHandle($mh, $ch);
        self::$state[$multi->id]['errno'] = $rc;
        if (CurlConstants::CURLM_OK === $rc) {
            self::$state[$multi->id]['easy_ids'][$easy->id] = $easy;
            VmCurlEasy::setMultiId($easy, $multi->id);
        } else {
            VmCurlEasy::cleanupMultiWriteBuffers($easy);
        }

        return $rc;
    }

    public static function removeHandle(ObjectEntry $multi, ObjectEntry $easy): int
    {
        self::ensureLive($multi, 'curl_multi_remove_handle');
        $mh = self::$state[$multi->id]['native'] ?? null;
        $ch = VmCurlEasy::nativeHandle($easy);
        if (null === $mh || null === $ch) {
            self::$state[$multi->id]['errno'] = CurlConstants::CURLM_BAD_EASY_HANDLE;

            return CurlConstants::CURLM_BAD_EASY_HANDLE;
        }
        VmCurlEasy::harvestMultiTransfer($easy, true);
        $rc = VmCurlNative::multiRemoveHandle($mh, $ch);
        self::$state[$multi->id]['errno'] = $rc;
        if (CurlConstants::CURLM_OK === $rc) {
            unset(self::$state[$multi->id]['easy_ids'][$easy->id]);
            VmCurlEasy::setMultiId($easy, null);
            VmCurlEasy::cleanupMultiWriteBuffers($easy);
        }

        return $rc;
    }

    /**
     * @return array{0: int, 1: int} CURLMcode, still_running
     */
    public static function exec(ObjectEntry $multi, int $stillRunning): array
    {
        self::ensureLive($multi, 'curl_multi_exec');
        $mh = self::$state[$multi->id]['native'];
        if (null === $mh) {
            self::$state[$multi->id]['errno'] = CurlConstants::CURLM_BAD_HANDLE;

            return [CurlConstants::CURLM_BAD_HANDLE, 0];
        }
        [$rc, $running] = VmCurlNative::multiPerform($mh, $stillRunning);
        self::$state[$multi->id]['errno'] = $rc;
        foreach (self::$state[$multi->id]['easy_ids'] as $easy) {
            VmCurlEasy::harvestMultiTransfer($easy, 0 === $running);
        }

        return [$rc, $running];
    }

    public static function select(ObjectEntry $multi, float $timeout = 1.0): int
    {
        self::ensureLive($multi, 'curl_multi_select');
        if (!($timeout >= 0.0 && $timeout <= (PHP_INT_MAX / 1000.0))) {
            throw new \ValueError(\sprintf(
                'curl_multi_select(): Argument #2 ($timeout) must be between 0 and %f',
                PHP_INT_MAX / 1000.0
            ));
        }
        $mh = self::$state[$multi->id]['native'];
        if (null === $mh) {
            self::$state[$multi->id]['errno'] = CurlConstants::CURLM_BAD_HANDLE;

            return -1;
        }
        [$rc, $numfds] = VmCurlNative::multiWait($mh, (int) ($timeout * 1000.0));
        if (CurlConstants::CURLM_OK !== $rc) {
            self::$state[$multi->id]['errno'] = $rc;

            return -1;
        }

        return $numfds;
    }

    public static function getcontent(ObjectEntry $easy): ?string
    {
        VmCurlEasy::ensureLivePublic($easy, 'curl_multi_getcontent');
        VmCurlEasy::harvestMultiTransfer($easy, true);
        if (!VmCurlEasy::isReturnTransfer($easy)) {
            return null;
        }

        return VmCurlEasy::lastBody($easy);
    }

    /**
     * curl_multi_get_handles() — easy handles currently attached (php-src ext/curl/multi.c; #20520).
     *
     * @return list<ObjectEntry>
     */
    public static function getHandles(ObjectEntry $multi): array
    {
        self::ensureLive($multi, 'curl_multi_get_handles');
        if (!isset(self::$state[$multi->id])) {
            return [];
        }

        return array_values(self::$state[$multi->id]['easy_ids']);
    }

    /**
     * curl_multi_info_read() — dequeue CURLMsg (php-src ext/curl/multi.c; #20495).
     *
     * @return array{0: ?array{msg: int, result: int, handle: ?ObjectEntry}, 1: int}
     */
    public static function infoRead(ObjectEntry $multi): array
    {
        self::ensureLive($multi, 'curl_multi_info_read');
        $mh = self::$state[$multi->id]['native'] ?? null;
        if (null === $mh) {
            return [null, 0];
        }
        [$payload, $queued] = VmCurlNative::multiInfoRead($mh);
        if (null === $payload) {
            return [null, $queued];
        }
        $easy = self::findEasyByNative($multi, $payload['easy']);
        if (null !== $easy) {
            VmCurlEasy::saveTransferResult($easy, $payload['result']);
        }

        return [[
            'msg' => $payload['msg'],
            'result' => $payload['result'],
            'handle' => $easy,
        ], $queued];
    }

    /**
     * curl_multi_setopt() — long multi options (php-src ext/curl/multi.c; #20495).
     */
    public static function setopt(ObjectEntry $multi, int $option, Variable $value): bool
    {
        self::ensureLive($multi, 'curl_multi_setopt');
        if (!CurlConstants::isValidMultiOption($option)) {
            self::$state[$multi->id]['errno'] = CurlConstants::CURLM_UNKNOWN_OPTION;
            throw new \ValueError('curl_multi_setopt(): Argument #2 ($option) is not a valid cURL multi option');
        }
        $mh = self::$state[$multi->id]['native'] ?? null;
        if (null === $mh) {
            self::$state[$multi->id]['errno'] = CurlConstants::CURLM_BAD_HANDLE;

            return false;
        }
        $lval = VmMath::parseIntBuiltinArg($value, 'curl_multi_setopt', 3, 'value');
        $rc = VmCurlNative::multiSetoptLong($mh, $option, $lval);
        self::$state[$multi->id]['errno'] = $rc;

        return CurlConstants::CURLM_OK === $rc;
    }

    /**
     * curl_multi_errno() — last CURLMcode (php-src ext/curl/multi.c; #20495).
     */
    public static function errno(ObjectEntry $multi): int
    {
        self::ensureLive($multi, 'curl_multi_errno');
        if (!isset(self::$state[$multi->id])) {
            return CurlConstants::CURLM_OK;
        }

        return self::$state[$multi->id]['errno'];
    }

    public static function close(ObjectEntry $multi): void
    {
        if (!isset(self::$state[$multi->id])) {
            return;
        }
        $mh = self::$state[$multi->id]['native'];
        foreach (self::$state[$multi->id]['easy_ids'] as $easy) {
            $ch = VmCurlEasy::nativeHandle($easy);
            if (null !== $mh && null !== $ch) {
                VmCurlEasy::harvestMultiTransfer($easy, true);
                VmCurlNative::multiRemoveHandle($mh, $ch);
            }
            VmCurlEasy::setMultiId($easy, null);
            VmCurlEasy::cleanupMultiWriteBuffers($easy);
        }
        if (null !== $mh) {
            VmCurlNative::multiCleanup($mh);
        }
        self::$state[$multi->id]['closed'] = true;
        self::$state[$multi->id]['native'] = null;
        self::$state[$multi->id]['easy_ids'] = [];
        unset(self::$state[$multi->id]);
    }

    public static function isMultiObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    /**
     * @param \FFI\CData $easyNative CURL*
     */
    private static function findEasyByNative(ObjectEntry $multi, \FFI\CData $easyNative): ?ObjectEntry
    {
        $want = VmCurlNative::pointerId($easyNative);
        foreach (self::$state[$multi->id]['easy_ids'] as $easy) {
            $ch = VmCurlEasy::nativeHandle($easy);
            if (null !== $ch && VmCurlNative::pointerId($ch) === $want) {
                return $easy;
            }
        }

        return null;
    }

    private static function ensureLive(ObjectEntry $multi, string $function): void
    {
        if (!self::isMultiObject($multi)) {
            throw new \TypeError($function.'(): Argument #1 ($multi_handle) must be of type CurlMultiHandle');
        }
        if (!isset(self::$state[$multi->id])) {
            return;
        }
    }
}
