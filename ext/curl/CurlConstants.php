<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * curl extension constants (php-src ext/curl/curl.stub.php; issue #6999, #3325).
 *
 * CURLOPT_RETURNTRANSFER is a PHP-level option (not forwarded to libcurl).
 * CURLINFO_* register with loaded ext/curl (#11669).
 */
final class CurlConstants
{
    public const CURLOPT_WRITEDATA = 10001;
    public const CURLOPT_URL = 10002;
    public const CURLOPT_HTTPHEADER = 10023;
    public const CURLOPT_SHARE = 10100;
    public const CURLOPT_POST = 47;
    public const CURLOPT_NOBODY = 44;
    /** PHP-only — see php-src ext/curl/interface.c */
    public const CURLOPT_RETURNTRANSFER = 19913;
    public const CURLINFO_EFFECTIVE_URL = 1048577;
    public const CURLINFO_HTTP_CODE = 2097154;
    public const CURLE_OK = 0;
    public const CURLM_CALL_MULTI_PERFORM = -1;
    public const CURLM_OK = 0;
    public const CURLM_BAD_HANDLE = 1;
    public const CURLM_BAD_EASY_HANDLE = 2;
    public const CURLM_OUT_OF_MEMORY = 3;
    public const CURLM_INTERNAL_ERROR = 4;
    /** libcurl CURLM_UNKNOWN_OPTION — php-src SAVE_CURLM_ERROR on bad setopt (#20495). */
    public const CURLM_UNKNOWN_OPTION = 6;
    public const CURLM_ADDED_ALREADY = 7;
    /** CURLMSG_DONE — completed transfer message (curl.h / php-src curl.stub.php; #20495). */
    public const CURLMSG_DONE = 1;
    /** Long multi options (curl/multi.h; #20495). */
    public const CURLMOPT_PIPELINING = 3;
    public const CURLMOPT_MAXCONNECTS = 6;
    public const CURLMOPT_MAX_HOST_CONNECTIONS = 7;
    public const CURLMOPT_MAX_PIPELINE_LENGTH = 8;
    public const CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE = 30009;
    public const CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE = 30010;
    public const CURLMOPT_MAX_TOTAL_CONNECTIONS = 13;
    public const CURLMOPT_MAX_CONCURRENT_STREAMS = 16;
    public const CURLSHOPT_NONE = 0;
    public const CURLSHOPT_SHARE = 1;
    public const CURLSHOPT_UNSHARE = 2;
    public const CURL_LOCK_DATA_COOKIE = 2;
    public const CURL_LOCK_DATA_DNS = 3;
    public const CURL_LOCK_DATA_SSL_SESSION = 4;
    public const CURL_LOCK_DATA_CONNECT = 5;
    /** curl_easy_pause bitmasks (curl/curl.h; php-src curl.stub.php; #20494). */
    public const CURLPAUSE_RECV = 1;
    public const CURLPAUSE_RECV_CONT = 0;
    public const CURLPAUSE_SEND = 4;
    public const CURLPAUSE_SEND_CONT = 0;
    public const CURLPAUSE_ALL = 5; // CURLPAUSE_RECV | CURLPAUSE_SEND
    public const CURLPAUSE_CONT = 0; // CURLPAUSE_RECV_CONT | CURLPAUSE_SEND_CONT

    /** @var array<int, true> */
    private const EASY_OPTIONS = [
        self::CURLOPT_URL => true,
        self::CURLOPT_RETURNTRANSFER => true,
        self::CURLOPT_POST => true,
        self::CURLOPT_HTTPHEADER => true,
        self::CURLOPT_SHARE => true,
        self::CURLOPT_NOBODY => true,
    ];

    /** @var array<int, true> long CURLMOPT_* accepted by curl_multi_setopt (#20495) */
    private const MULTI_OPTIONS = [
        self::CURLMOPT_PIPELINING => true,
        self::CURLMOPT_MAXCONNECTS => true,
        self::CURLMOPT_MAX_HOST_CONNECTIONS => true,
        self::CURLMOPT_MAX_PIPELINE_LENGTH => true,
        self::CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE => true,
        self::CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE => true,
        self::CURLMOPT_MAX_TOTAL_CONNECTIONS => true,
        self::CURLMOPT_MAX_CONCURRENT_STREAMS => true,
    ];

    public static function isValidEasyOption(int $option): bool
    {
        return isset(self::EASY_OPTIONS[$option]);
    }

    public static function isValidMultiOption(int $option): bool
    {
        return isset(self::MULTI_OPTIONS[$option]);
    }

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        $constants = [
            'CURLOPT_URL' => self::CURLOPT_URL,
            'CURLOPT_RETURNTRANSFER' => self::CURLOPT_RETURNTRANSFER,
            'CURLOPT_POST' => self::CURLOPT_POST,
            'CURLOPT_HTTPHEADER' => self::CURLOPT_HTTPHEADER,
            'CURLOPT_SHARE' => self::CURLOPT_SHARE,
            'CURLOPT_NOBODY' => self::CURLOPT_NOBODY,
            'CURLE_OK' => self::CURLE_OK,
            'CURLM_CALL_MULTI_PERFORM' => self::CURLM_CALL_MULTI_PERFORM,
            'CURLM_OK' => self::CURLM_OK,
            'CURLM_BAD_HANDLE' => self::CURLM_BAD_HANDLE,
            'CURLM_BAD_EASY_HANDLE' => self::CURLM_BAD_EASY_HANDLE,
            'CURLM_OUT_OF_MEMORY' => self::CURLM_OUT_OF_MEMORY,
            'CURLM_INTERNAL_ERROR' => self::CURLM_INTERNAL_ERROR,
            'CURLM_ADDED_ALREADY' => self::CURLM_ADDED_ALREADY,
            'CURLSHOPT_NONE' => self::CURLSHOPT_NONE,
            'CURLSHOPT_SHARE' => self::CURLSHOPT_SHARE,
            'CURLSHOPT_UNSHARE' => self::CURLSHOPT_UNSHARE,
            'CURL_LOCK_DATA_COOKIE' => self::CURL_LOCK_DATA_COOKIE,
            'CURL_LOCK_DATA_DNS' => self::CURL_LOCK_DATA_DNS,
            'CURL_LOCK_DATA_SSL_SESSION' => self::CURL_LOCK_DATA_SSL_SESSION,
            'CURL_LOCK_DATA_CONNECT' => self::CURL_LOCK_DATA_CONNECT,
        ];
        if (CurlExtensionPolicy::advertisesExtension()) {
            $constants['CURLINFO_HTTP_CODE'] = self::CURLINFO_HTTP_CODE;
            $constants['CURLINFO_EFFECTIVE_URL'] = self::CURLINFO_EFFECTIVE_URL;
            $constants['CURLPAUSE_ALL'] = self::CURLPAUSE_ALL;
            $constants['CURLPAUSE_CONT'] = self::CURLPAUSE_CONT;
            $constants['CURLPAUSE_RECV'] = self::CURLPAUSE_RECV;
            $constants['CURLPAUSE_RECV_CONT'] = self::CURLPAUSE_RECV_CONT;
            $constants['CURLPAUSE_SEND'] = self::CURLPAUSE_SEND;
            $constants['CURLPAUSE_SEND_CONT'] = self::CURLPAUSE_SEND_CONT;
            $constants['CURLMSG_DONE'] = self::CURLMSG_DONE;
            $constants['CURLMOPT_PIPELINING'] = self::CURLMOPT_PIPELINING;
            $constants['CURLMOPT_MAXCONNECTS'] = self::CURLMOPT_MAXCONNECTS;
            $constants['CURLMOPT_MAX_HOST_CONNECTIONS'] = self::CURLMOPT_MAX_HOST_CONNECTIONS;
            $constants['CURLMOPT_MAX_PIPELINE_LENGTH'] = self::CURLMOPT_MAX_PIPELINE_LENGTH;
            $constants['CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE'] = self::CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE;
            $constants['CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE'] = self::CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE;
            $constants['CURLMOPT_MAX_TOTAL_CONNECTIONS'] = self::CURLMOPT_MAX_TOTAL_CONNECTIONS;
            $constants['CURLMOPT_MAX_CONCURRENT_STREAMS'] = self::CURLMOPT_MAX_CONCURRENT_STREAMS;
        }

        return $constants;
    }
}
