<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;

/**
 * CurlHandle easy API — libcurl FFI via {@see VmCurlNative} (php-src ext/curl/interface.c; #3325).
 *
 * php-src registers an opaque {@see CurlHandle} with no instance methods; pause/reset are
 * procedural only (ext/curl/curl.stub.php; #22595 — revert mistaken OOP surface from #21837).
 */
final class VmCurlEasy
{
    public const CLASS_LC = 'curlhandle';

    /**
     * @var array<int, array{
     *   closed: bool,
     *   url: ?string,
     *   share_id: ?int,
     *   return_transfer: bool,
     *   nobody: bool,
     *   post: bool,
     *   headers: list<string>,
     *   headers_on_handle: bool,
     *   errno: int,
     *   error: string,
     *   error_buf: ?\FFI\CData,
     *   http_code: int,
     *   effective_url: string,
     *   last_body: string,
     *   native: ?\FFI\CData,
     *   multi_id: ?int,
     *   write_tmp: ?string,
     *   write_fp: ?\FFI\CData,
     *   write_slist: ?\FFI\CData,
     *   multi_harvested: bool
     * }>
     */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('CurlHandle');
        $entry->isInternal = true;
        // php-src `final class CurlHandle` (ext/curl/curl.stub.php; #28371).
        $entry->isFinal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function init(?string $url, Context $ctx): Variable
    {
        self::registerClass($ctx);
        if (!VmCurlNative::available()) {
            throw new \LogicException('curl_init() requires libcurl FFI (issue #3325)');
        }
        $native = VmCurlNative::easyInit();
        $errorBuf = VmCurlNative::allocErrorBuffer();
        VmCurlNative::attachErrorBuffer($native, $errorBuf);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'closed' => false,
            'url' => $url,
            'share_id' => null,
            'return_transfer' => false,
            'nobody' => false,
            'post' => false,
            'headers' => [],
            'headers_on_handle' => false,
            'errno' => 0,
            'error' => '',
            'error_buf' => $errorBuf,
            'http_code' => 0,
            'effective_url' => '',
            'last_body' => '',
            'native' => $native,
            'multi_id' => null,
            'write_tmp' => null,
            'write_fp' => null,
            'write_slist' => null,
            'multi_harvested' => false,
        ];
        if (null !== $url && '' !== $url) {
            VmCurlNative::easySetoptString($native, CurlConstants::CURLOPT_URL, $url);
        }
        $var->object($object);

        return $var;
    }

    public static function setopt(ObjectEntry $easy, int $option, Variable $value, Frame $frame): bool
    {
        self::ensureLive($easy, 'curl_setopt');
        $st = &self::$state[$easy->id];
        $native = $st['native'];
        if (null === $native) {
            return false;
        }

        if (CurlConstants::CURLOPT_SHARE === $option) {
            $share = VmCurlArg::requireShareableObject($value, 'curl_setopt', 3);
            VmCurlShare::attachToEasy($share);
            $st['share_id'] = $share->id;

            return true;
        }
        if (CurlConstants::CURLOPT_URL === $option) {
            $url = VmString::coerceStringBuiltinArg($value, 'curl_setopt', 2, 'value');
            $st['url'] = $url;
            VmCurlNative::easySetoptString($native, CurlConstants::CURLOPT_URL, $url);

            return true;
        }
        if (CurlConstants::CURLOPT_RETURNTRANSFER === $option) {
            // PHP-level option — not a real libcurl CURLOPT (php-src ext/curl/interface.c).
            $st['return_transfer'] = self::toBoolOption($value, 'curl_setopt');

            return true;
        }
        if (CurlConstants::CURLOPT_NOBODY === $option) {
            $st['nobody'] = self::toBoolOption($value, 'curl_setopt');
            VmCurlNative::easySetoptLong($native, CurlConstants::CURLOPT_NOBODY, $st['nobody'] ? 1 : 0);

            return true;
        }
        if (CurlConstants::CURLOPT_POST === $option) {
            $st['post'] = self::toBoolOption($value, 'curl_setopt');
            VmCurlNative::easySetoptLong($native, CurlConstants::CURLOPT_POST, $st['post'] ? 1 : 0);

            return true;
        }
        if (CurlConstants::CURLOPT_HTTPHEADER === $option) {
            $headers = self::coerceHeaderList($value, 'curl_setopt');
            $st['headers'] = $headers;

            return true;
        }
        if (CurlConstants::CURLOPT_BINARYTRANSFER === $option
            || CurlConstants::CURLOPT_SAFE_UPLOAD === $option
        ) {
            // PHP-only historical options — accept and ignore (php-src ext/curl/interface.c).
            return true;
        }
        if (CurlConstants::CURLOPT_ERRORBUFFER === $option) {
            // Keep compiler-owned err.str for curl_error() (php-src ch->err.str; #25814).
            return true;
        }

        // Forward remaining CURLOPT_* to libcurl (#21137).
        $optType = CurlConstants::easyOptionType($option);
        if (2 === $optType) {
            // CURLOPTTYPE_FUNCTIONPOINT — callables not yet lowered; accept without fatal.
            return true;
        }
        if (1 === $optType || 4 === $optType) {
            // OBJECTPOINT / BLOB — string path (POSTFIELDS array multipart still TODO).
            if (Variable::TYPE_ARRAY === $value->resolveIndirect()->type) {
                // Zend builds multipart from array POSTFIELDS; keep soft-true until implemented.
                return CurlConstants::CURLOPT_POSTFIELDS === $option;
            }
            $str = VmString::coerceStringBuiltinArg($value, 'curl_setopt', 2, 'value');
            $rc = VmCurlNative::easySetoptString($native, $option, $str);

            return CurlConstants::CURLE_OK === $rc;
        }
        // LONG / OFF_T
        $long = self::toLongOption($value, 'curl_setopt');
        $rc = VmCurlNative::easySetoptLong($native, $option, $long);

        return CurlConstants::CURLE_OK === $rc;
    }

    public static function setoptArray(ObjectEntry $easy, Variable $optionsVar, Frame $frame): bool
    {
        self::ensureLive($easy, 'curl_setopt_array');
        $optionsVar = $optionsVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $optionsVar->type) {
            throw new \TypeError(\sprintf(
                'curl_setopt_array(): Argument #2 ($options) must be of type array, %s given',
                EnumCaseSupport::typeNameForVariable($optionsVar)
            ));
        }

        foreach ($optionsVar->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $keyVar = $keyVar->resolveIndirect();
            $option = self::parseOptionKey($keyVar);
            if (!CurlConstants::isValidEasyOption($option)) {
                throw new \ValueError(
                    Variable::TYPE_STRING === $keyVar->type && !is_numeric($keyVar->toString())
                        ? 'curl_setopt_array(): Argument #2 ($options) contains an invalid cURL option'
                        : 'curl_setopt_array(): Argument #2 ($options) must contain only valid cURL options'
                );
            }
            if (!self::setopt($easy, $option, $valueVar, $frame)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string|bool
     */
    public static function exec(ObjectEntry $easy)
    {
        self::ensureLive($easy, 'curl_exec');
        $st = &self::$state[$easy->id];
        $native = $st['native'];
        if (null === $native) {
            return false;
        }
        if (null === $st['url'] || '' === $st['url']) {
            self::saveCurlError($st, 3); // CURLE_URL_MALFORMAT
            $st['http_code'] = 0;
            $st['last_body'] = '';

            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'phpc_curl_');
        if (false === $tmp) {
            $st['errno'] = 27;
            $st['error'] = 'Out of memory';

            return false;
        }
        $fp = null;
        $slist = null;
        try {
            $fp = VmCurlNative::fopen($tmp, 'w+b');
            VmCurlNative::easySetoptPtr($native, CurlConstants::CURLOPT_WRITEDATA, $fp);

            if ([] !== $st['headers']) {
                foreach ($st['headers'] as $header) {
                    $slist = VmCurlNative::slistAppend($slist, $header);
                }
                VmCurlNative::easySetoptPtr($native, CurlConstants::CURLOPT_HTTPHEADER, $slist);
                $st['headers_on_handle'] = true;
            } elseif ($st['headers_on_handle']) {
                VmCurlNative::easySetoptNull($native, CurlConstants::CURLOPT_HTTPHEADER);
                $st['headers_on_handle'] = false;
            }

            if (null !== $st['error_buf']) {
                VmCurlNative::clearErrorBuffer($st['error_buf']);
            }
            $rc = VmCurlNative::easyPerform($native);
            self::saveCurlError($st, $rc);
            $st['http_code'] = VmCurlNative::easyGetinfoLong($native, CurlConstants::CURLINFO_HTTP_CODE);
            $st['effective_url'] = VmCurlNative::easyGetinfoString($native, CurlConstants::CURLINFO_EFFECTIVE_URL);

            VmCurlNative::rewind($fp);
            $body = VmCurlNative::freadAll($fp);
            $st['last_body'] = $body;

            if (0 !== $rc) {
                return false;
            }
            if ($st['return_transfer']) {
                return $body;
            }
            echo $body;

            return true;
        } finally {
            if (null !== $slist) {
                VmCurlNative::slistFreeAll($slist);
            }
            if (null !== $fp) {
                VmCurlNative::fclose($fp);
            }
            @unlink($tmp);
        }
    }

    public static function getinfo(ObjectEntry $easy, ?int $option = null): mixed
    {
        self::ensureLive($easy, 'curl_getinfo');
        $st = self::$state[$easy->id];
        if (null === $option) {
            // Key order matches php-src ext/curl/interface.c PHP_FUNCTION(curl_getinfo) (#21883).
            return [
                'url' => $st['effective_url'] !== '' ? $st['effective_url'] : (string) ($st['url'] ?? ''),
                'content_type' => self::contentTypeForAllInfo($st),
                'http_code' => $st['http_code'],
                'header_size' => 0,
                'request_size' => 0,
                'filetime' => -1,
                'ssl_verify_result' => 0,
                'redirect_count' => 0,
                'total_time' => 0.0,
                'namelookup_time' => 0.0,
                'connect_time' => 0.0,
                'pretransfer_time' => 0.0,
                'size_upload' => 0.0,
                'size_download' => 0.0,
                'speed_download' => 0.0,
                'speed_upload' => 0.0,
                'download_content_length' => -1.0,
                'upload_content_length' => -1.0,
                'starttransfer_time' => 0.0,
                'redirect_time' => 0.0,
                'redirect_url' => '',
                'primary_ip' => '',
                'certinfo' => [],
                'primary_port' => 0,
                'local_ip' => '',
                'local_port' => 0,
                'http_version' => 0,
                'protocol' => 0,
                'ssl_verifyresult' => 0,
                'scheme' => '',
                'appconnect_time_us' => 0,
                'connect_time_us' => 0,
                'namelookup_time_us' => 0,
                'pretransfer_time_us' => 0,
                'redirect_time_us' => 0,
                'starttransfer_time_us' => 0,
                'total_time_us' => 0,
                'effective_method' => self::effectiveMethod($st),
            ];
        }
        if (CurlConstants::CURLINFO_HTTP_CODE === $option) {
            return $st['http_code'];
        }
        if (CurlConstants::CURLINFO_EFFECTIVE_URL === $option) {
            return $st['effective_url'] !== '' ? $st['effective_url'] : (string) ($st['url'] ?? '');
        }
        if (CurlConstants::CURLINFO_CONTENT_TYPE === $option) {
            // CURLINFO_STRING: false when libcurl returns NULL (php-src interface.c).
            $native = $st['native'];
            if (null === $native) {
                return false;
            }
            [$ok, $value] = VmCurlNative::easyGetinfoStringResult($native, CurlConstants::CURLINFO_CONTENT_TYPE);

            return ($ok && null !== $value) ? $value : false;
        }
        if (CurlConstants::CURLINFO_EFFECTIVE_METHOD === $option) {
            return self::effectiveMethod($st);
        }

        return false;
    }

    /**
     * All-info content_type: string or null when OK+NULL (php-src interface.c; #21883).
     *
     * @param array<string, mixed> $st
     */
    private static function contentTypeForAllInfo(array $st): ?string
    {
        $native = $st['native'] ?? null;
        if (null === $native) {
            return null;
        }
        [$ok, $value] = VmCurlNative::easyGetinfoStringResult($native, CurlConstants::CURLINFO_CONTENT_TYPE);
        if (!$ok) {
            return null;
        }

        return $value;
    }

    /**
     * Effective HTTP method — libcurl CURLINFO_EFFECTIVE_METHOD (pre-perform: "GET"; #21883).
     *
     * @param array<string, mixed> $st
     */
    private static function effectiveMethod(array $st): string
    {
        $native = $st['native'] ?? null;
        if (null === $native) {
            return 'GET';
        }
        [$ok, $value] = VmCurlNative::easyGetinfoStringResult($native, CurlConstants::CURLINFO_EFFECTIVE_METHOD);
        if ($ok && null !== $value && '' !== $value) {
            return $value;
        }

        return 'GET';
    }

    public static function error(ObjectEntry $easy): string
    {
        self::ensureLive($easy, 'curl_error');

        return self::$state[$easy->id]['error'];
    }

    public static function errno(ObjectEntry $easy): int
    {
        self::ensureLive($easy, 'curl_errno');

        return self::$state[$easy->id]['errno'];
    }

    public static function close(ObjectEntry $easy): void
    {
        if (!isset(self::$state[$easy->id])) {
            return;
        }
        self::cleanupMultiWriteBuffers($easy);
        $native = self::$state[$easy->id]['native'];
        if (null !== $native) {
            VmCurlNative::easyCleanup($native);
        }
        self::$state[$easy->id]['closed'] = true;
        self::$state[$easy->id]['native'] = null;
        unset(self::$state[$easy->id]);
    }

    /**
     * curl_reset() — curl_easy_reset + PHP handler defaults
     * (php-src ext/curl/interface.c PHP_FUNCTION(curl_reset); #20494).
     */
    public static function reset(ObjectEntry $easy): void
    {
        self::ensureLive($easy, 'curl_reset');
        if (!isset(self::$state[$easy->id])) {
            return;
        }
        $st = &self::$state[$easy->id];
        $native = $st['native'];
        if (null === $native) {
            return;
        }
        self::cleanupMultiWriteBuffers($easy);
        VmCurlNative::easyReset($native);
        if (null !== $st['error_buf']) {
            VmCurlNative::clearErrorBuffer($st['error_buf']);
            VmCurlNative::attachErrorBuffer($native, $st['error_buf']);
        }
        $st['url'] = null;
        $st['share_id'] = null;
        $st['return_transfer'] = false;
        $st['nobody'] = false;
        $st['post'] = false;
        $st['headers'] = [];
        $st['headers_on_handle'] = false;
        $st['errno'] = 0;
        $st['error'] = '';
        $st['http_code'] = 0;
        $st['effective_url'] = '';
        $st['last_body'] = '';
        $st['multi_harvested'] = false;
    }

    /**
     * curl_pause() — curl_easy_pause (php-src ext/curl/interface.c; #20494).
     */
    public static function pause(ObjectEntry $easy, int $flags): int
    {
        self::ensureLive($easy, 'curl_pause');
        if (!isset(self::$state[$easy->id])) {
            return CurlConstants::CURLE_OK;
        }
        $native = self::$state[$easy->id]['native'];
        if (null === $native) {
            return CurlConstants::CURLE_OK;
        }

        return VmCurlNative::easyPause($native, $flags);
    }

    /**
     * curl_upkeep() — curl_easy_upkeep + SAVE_CURL_ERROR → bool (php-src interface.c; #20977).
     */
    public static function upkeep(ObjectEntry $easy): bool
    {
        self::ensureLive($easy, 'curl_upkeep');
        if (!isset(self::$state[$easy->id])) {
            return true;
        }
        $native = self::$state[$easy->id]['native'];
        if (null === $native) {
            return true;
        }
        $rc = VmCurlNative::easyUpkeep($native);
        self::setEasyResult($easy, $rc);

        return CurlConstants::CURLE_OK === $rc;
    }

    /**
     * curl_copy_handle() — curl_easy_duphandle + PHP option clone
     * (php-src ext/curl/interface.c; #20495).
     */
    public static function copyHandle(ObjectEntry $easy, Context $ctx): ?Variable
    {
        self::ensureLive($easy, 'curl_copy_handle');
        if (!isset(self::$state[$easy->id])) {
            return null;
        }
        $src = self::$state[$easy->id];
        $native = $src['native'];
        if (null === $native) {
            return null;
        }
        $dup = VmCurlNative::easyDuphandle($native);
        if (null === $dup) {
            @\trigger_error('curl_copy_handle(): Cannot duplicate cURL handle', \E_WARNING);

            return null;
        }
        self::registerClass($ctx);
        $errorBuf = VmCurlNative::allocErrorBuffer();
        VmCurlNative::attachErrorBuffer($dup, $errorBuf);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'closed' => false,
            'url' => $src['url'],
            'share_id' => $src['share_id'],
            'return_transfer' => $src['return_transfer'],
            'nobody' => $src['nobody'],
            'post' => $src['post'],
            'headers' => $src['headers'],
            'headers_on_handle' => false,
            'errno' => 0,
            'error' => '',
            'error_buf' => $errorBuf,
            'http_code' => 0,
            'effective_url' => '',
            'last_body' => '',
            'native' => $dup,
            'multi_id' => null,
            'write_tmp' => null,
            'write_fp' => null,
            'write_slist' => null,
            'multi_harvested' => false,
        ];
        $var->object($object);

        return $var;
    }

    /**
     * SAVE_CURL_ERROR after multi info_read (php-src multi.c; #20495).
     * Prefer CURLOPT_ERRORBUFFER text over curl_easy_strerror (#25814).
     */
    public static function saveTransferResult(ObjectEntry $easy, int $result): void
    {
        if (!isset(self::$state[$easy->id])) {
            return;
        }
        $st = &self::$state[$easy->id];
        self::saveCurlError($st, $result);
    }

    public static function isEasyObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function isLiveEasyObject(ObjectEntry $object): bool
    {
        return self::isEasyObject($object) && isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    /** Public wrapper for multi API type checks (php-src Z_PARAM_OBJECT_OF_CLASS). */
    public static function ensureLivePublic(ObjectEntry $easy, string $function): void
    {
        self::ensureLive($easy, $function);
    }

    public static function shareIdForEasy(ObjectEntry $easy): ?int
    {
        return self::$state[$easy->id]['share_id'] ?? null;
    }

    /**
     * @return \FFI\CData|null CURL*
     */
    public static function nativeHandle(ObjectEntry $easy): ?\FFI\CData
    {
        self::ensureLive($easy, 'curl_multi');

        return self::$state[$easy->id]['native'] ?? null;
    }

    public static function multiIdForEasy(ObjectEntry $easy): ?int
    {
        return self::$state[$easy->id]['multi_id'] ?? null;
    }

    public static function setMultiId(ObjectEntry $easy, ?int $multiId): void
    {
        if (!isset(self::$state[$easy->id])) {
            return;
        }
        self::$state[$easy->id]['multi_id'] = $multiId;
    }

    public static function isReturnTransfer(ObjectEntry $easy): bool
    {
        return (bool) (self::$state[$easy->id]['return_transfer'] ?? false);
    }

    public static function lastBody(ObjectEntry $easy): string
    {
        return (string) (self::$state[$easy->id]['last_body'] ?? '');
    }

    /**
     * Attach write buffer / headers for a multi transfer (php-src write handler setup).
     */
    public static function prepareMultiTransfer(ObjectEntry $easy): void
    {
        self::ensureLive($easy, 'curl_multi_add_handle');
        $st = &self::$state[$easy->id];
        $native = $st['native'];
        if (null === $native) {
            throw new \LogicException('curl_multi_add_handle(): easy handle has no libcurl handle');
        }
        self::cleanupMultiWriteBuffers($easy);
        $st['multi_harvested'] = false;
        $st['last_body'] = '';
        $st['errno'] = 0;
        $st['error'] = '';
        if (null !== $st['error_buf']) {
            VmCurlNative::clearErrorBuffer($st['error_buf']);
        }
        $st['http_code'] = 0;
        $st['effective_url'] = '';

        $tmp = tempnam(sys_get_temp_dir(), 'phpc_curlm_');
        if (false === $tmp) {
            throw new \RuntimeException('Out of memory');
        }
        $fp = VmCurlNative::fopen($tmp, 'w+b');
        VmCurlNative::easySetoptPtr($native, CurlConstants::CURLOPT_WRITEDATA, $fp);
        $st['write_tmp'] = $tmp;
        $st['write_fp'] = $fp;

        $slist = null;
        if ([] !== $st['headers']) {
            foreach ($st['headers'] as $header) {
                $slist = VmCurlNative::slistAppend($slist, $header);
            }
            VmCurlNative::easySetoptPtr($native, CurlConstants::CURLOPT_HTTPHEADER, $slist);
            $st['headers_on_handle'] = true;
        } elseif ($st['headers_on_handle']) {
            VmCurlNative::easySetoptNull($native, CurlConstants::CURLOPT_HTTPHEADER);
            $st['headers_on_handle'] = false;
        }
        $st['write_slist'] = $slist;
    }

    /**
     * Refresh getinfo / body after multi perform (or remove/close).
     */
    public static function harvestMultiTransfer(ObjectEntry $easy, bool $forceBody = false): void
    {
        if (!isset(self::$state[$easy->id])) {
            return;
        }
        $st = &self::$state[$easy->id];
        $native = $st['native'];
        if (null === $native) {
            return;
        }
        $st['http_code'] = VmCurlNative::easyGetinfoLong($native, CurlConstants::CURLINFO_HTTP_CODE);
        $st['effective_url'] = VmCurlNative::easyGetinfoString($native, CurlConstants::CURLINFO_EFFECTIVE_URL);
        if (!$forceBody && $st['multi_harvested']) {
            return;
        }
        if (null !== $st['write_fp']) {
            VmCurlNative::rewind($st['write_fp']);
            $st['last_body'] = VmCurlNative::freadAll($st['write_fp']);
        }
        $st['multi_harvested'] = true;
    }

    public static function setEasyResult(ObjectEntry $easy, int $errno): void
    {
        if (!isset(self::$state[$easy->id])) {
            return;
        }
        $st = &self::$state[$easy->id];
        self::saveCurlError($st, $errno);
    }

    public static function cleanupMultiWriteBuffers(ObjectEntry $easy): void
    {
        if (!isset(self::$state[$easy->id])) {
            return;
        }
        $st = &self::$state[$easy->id];
        if (null !== $st['write_slist']) {
            VmCurlNative::slistFreeAll($st['write_slist']);
            $st['write_slist'] = null;
        }
        if (null !== $st['write_fp']) {
            VmCurlNative::fclose($st['write_fp']);
            $st['write_fp'] = null;
        }
        if (null !== $st['write_tmp']) {
            @unlink($st['write_tmp']);
            $st['write_tmp'] = null;
        }
    }

    /**
     * php-src SAVE_CURL_ERROR + curl_error() buffer preference
     * (ext/curl/interface.c; CURLOPT_ERRORBUFFER; #25814 / php-src #14984).
     *
     * @param array{
     *   errno: int,
     *   error: string,
     *   error_buf: ?\FFI\CData,
     *   ...
     * } $st
     */
    private static function saveCurlError(array &$st, int $errno): void
    {
        $st['errno'] = $errno;
        if (0 === $errno) {
            $st['error'] = '';

            return;
        }
        $bufMsg = '';
        if (null !== $st['error_buf']) {
            $bufMsg = VmCurlNative::errorBufferString($st['error_buf']);
        }
        $st['error'] = '' !== $bufMsg ? $bufMsg : VmCurlNative::easyStrerror($errno);
    }

    private static function ensureLive(ObjectEntry $easy, string $function): void
    {
        if (!self::isEasyObject($easy)) {
            throw new \TypeError($function.'(): Argument #1 ($handle) must be of type CurlHandle');
        }
        if (!isset(self::$state[$easy->id])) {
            return;
        }
    }

    private static function toBoolOption(Variable $value, string $function): bool
    {
        $value = $value->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #3 ($value) must not be an enum case',
                $function
            ));
        }
        if (Variable::TYPE_BOOLEAN === $value->type) {
            return $value->toBool();
        }
        if (Variable::TYPE_INTEGER === $value->type) {
            return 0 !== $value->toInt();
        }
        if (Variable::TYPE_NULL === $value->type) {
            return false;
        }

        return (bool) $value->toBool();
    }

    /** Coerce CURLOPT long/bool values like php-src zend_parse_parameters "l" (#21137). */
    private static function toLongOption(Variable $value, string $function): int
    {
        $value = $value->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #3 ($value) must not be an enum case',
                $function
            ));
        }
        if (Variable::TYPE_BOOLEAN === $value->type) {
            return $value->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_NULL === $value->type) {
            return 0;
        }

        return VmMath::parseIntBuiltinArg($value, $function, 2, 'value');
    }

    /** @return list<string> */
    private static function coerceHeaderList(Variable $value, string $function): array
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $value->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #3 ($value) must be of type array, %s given',
                $function,
                EnumCaseSupport::typeNameForVariable($value)
            ));
        }
        $headers = [];
        foreach ($value->toArray()->iterateKeyed(true) as [, $headerVar]) {
            $headers[] = VmString::coerceStringBuiltinArg($headerVar, $function, 2, 'value');
        }

        return $headers;
    }

    private static function parseOptionKey(Variable $keyVar): int
    {
        if (Variable::TYPE_INTEGER === $keyVar->type) {
            return $keyVar->toInt();
        }
        if (Variable::TYPE_STRING === $keyVar->type) {
            $s = $keyVar->toString();
            if ('' === $s || !is_numeric($s)) {
                throw new \ValueError('curl_setopt_array(): Argument #2 ($options) contains an invalid cURL option');
            }

            return VmMath::parseIntBuiltinArg($keyVar, 'curl_setopt_array', 2, 'options');
        }

        throw new \ValueError('curl_setopt_array(): Argument #2 ($options) contains an invalid cURL option');
    }
}
