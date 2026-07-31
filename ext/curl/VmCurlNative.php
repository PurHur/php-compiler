<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * Thin libcurl FFI bridge for easy + multi HTTP I/O
 * (php-src ext/curl/interface.c, multi.c; #3325, #3721).
 *
 * Semantics live in {@see VmCurlEasy} / {@see VmCurlMulti}; this class only loads
 * libcurl/libc and exposes curl_easy_* / curl_multi_* / FILE* helpers — no new runtime/ C.
 */
final class VmCurlNative
{
    /** @var \FFI|null */
    private static $curlFfi = null;

    /** @var \FFI|null */
    private static $libcFfi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::curlFfi() && null !== self::libcFfi();
    }

    /**
     * @return \FFI\CData CURL*
     */
    public static function easyInit(): \FFI\CData
    {
        $ffi = self::requireCurl();
        $ch = $ffi->curl_easy_init();
        if (null === $ch) {
            throw new \RuntimeException('curl_easy_init() failed');
        }

        return $ch;
    }

    /**
     * @param \FFI\CData $ch CURL*
     */
    public static function easyCleanup(\FFI\CData $ch): void
    {
        self::requireCurl()->curl_easy_cleanup($ch);
    }

    /**
     * @param \FFI\CData $ch CURL*
     */
    public static function easyReset(\FFI\CData $ch): void
    {
        self::requireCurl()->curl_easy_reset($ch);
    }

    /**
     * @param \FFI\CData $ch CURL*
     */
    public static function easyPause(\FFI\CData $ch, int $bitmask): int
    {
        return (int) self::requireCurl()->curl_easy_pause($ch, $bitmask);
    }

    /**
     * curl_easy_upkeep() — connection keepalive (libcurl ≥7.62; php-src curl_upkeep; #20977).
     *
     * @param \FFI\CData $ch CURL*
     */
    public static function easyUpkeep(\FFI\CData $ch): int
    {
        return (int) self::requireCurl()->curl_easy_upkeep($ch);
    }

    /**
     * @param \FFI\CData $ch CURL*
     *
     * @return \FFI\CData|null duplicated CURL* (null on failure)
     */
    public static function easyDuphandle(\FFI\CData $ch): ?\FFI\CData
    {
        $dup = self::requireCurl()->curl_easy_duphandle($ch);

        return null === $dup ? null : $dup;
    }

    /**
     * Stable address for matching CURL* across FFI CData wrappers.
     *
     * @param \FFI\CData $ptr CURL* / void*
     */
    public static function pointerId(\FFI\CData $ptr): int
    {
        // Cast via void* then read uintptr_t->cdata (direct (int) on CData warns).
        $asVoid = \FFI::cast('void*', $ptr);
        $asInt = \FFI::cast('uintptr_t', $asVoid);

        return (int) $asInt->cdata;
    }

    /**
     * @param \FFI\CData $ch CURL*
     */
    public static function easySetoptLong(\FFI\CData $ch, int $option, int $value): int
    {
        return (int) self::requireCurl()->curl_easy_setopt($ch, $option, $value);
    }

    /**
     * @param \FFI\CData $ch CURL*
     */
    public static function easySetoptString(\FFI\CData $ch, int $option, string $value): int
    {
        return (int) self::requireCurl()->curl_easy_setopt($ch, $option, $value);
    }

    /**
     * @param \FFI\CData $ch CURL*
     */
    public static function easySetoptNull(\FFI\CData $ch, int $option): int
    {
        return (int) self::requireCurl()->curl_easy_setopt($ch, $option, null);
    }

    /**
     * @param \FFI\CData $ch CURL*
     * @param \FFI\CData $ptr void* / FILE* / curl_slist*
     */
    public static function easySetoptPtr(\FFI\CData $ch, int $option, \FFI\CData $ptr): int
    {
        return (int) self::requireCurl()->curl_easy_setopt($ch, $option, $ptr);
    }

    /**
     * @param \FFI\CData $ch CURL*
     */
    public static function easyPerform(\FFI\CData $ch): int
    {
        return (int) self::requireCurl()->curl_easy_perform($ch);
    }

    /**
     * @param \FFI\CData $ch CURL*
     */
    public static function easyGetinfoLong(\FFI\CData $ch, int $info): int
    {
        $ffi = self::requireCurl();
        $out = $ffi->new('long');
        $rc = (int) $ffi->curl_easy_getinfo($ch, $info, \FFI::addr($out));
        if (0 !== $rc) {
            return 0;
        }

        return (int) $out->cdata;
    }

    /**
     * @param \FFI\CData $ch CURL*
     */
    public static function easyGetinfoString(\FFI\CData $ch, int $info): string
    {
        $value = self::easyGetinfoStringOrNull($ch, $info);

        return null === $value ? '' : $value;
    }

    /**
     * CURLINFO_STRING getinfo — distinguish OK+NULL (null) from failure (null) vs non-null string.
     * php-src interface.c: content_type all-info uses NULL; option path returns false when s_code is NULL.
     *
     * @param \FFI\CData $ch CURL*
     *
     * @return array{0: bool, 1: ?string} [CURLE_OK, string|null]
     */
    public static function easyGetinfoStringResult(\FFI\CData $ch, int $info): array
    {
        $ffi = self::requireCurl();
        $out = $ffi->new('char*');
        $rc = (int) $ffi->curl_easy_getinfo($ch, $info, \FFI::addr($out));
        if (0 !== $rc) {
            return [false, null];
        }
        if (\is_string($out)) {
            return [true, $out];
        }
        if (\FFI::isNull($out)) {
            return [true, null];
        }

        return [true, (string) \FFI::string($out)];
    }

    /**
     * @param \FFI\CData $ch CURL*
     */
    public static function easyGetinfoStringOrNull(\FFI\CData $ch, int $info): ?string
    {
        [$ok, $value] = self::easyGetinfoStringResult($ch, $info);

        return $ok ? $value : null;
    }

    public static function easyStrerror(int $code): string
    {
        $msg = self::requireCurl()->curl_easy_strerror($code);

        return self::cstringToString($msg);
    }

    /**
     * CURL_ERROR_SIZE (+1 for NUL) — php-src php_curl.err.str (#25814).
     *
     * @return \FFI\CData char[257] owned by PHP (must outlive CURLOPT_ERRORBUFFER attach)
     */
    public static function allocErrorBuffer(): \FFI\CData
    {
        $buf = self::requireCurl()->new('char[257]');
        self::clearErrorBuffer($buf);

        return $buf;
    }

    /**
     * @param \FFI\CData $buf char[257]
     */
    public static function clearErrorBuffer(\FFI\CData $buf): void
    {
        for ($i = 0; $i < 257; ++$i) {
            $buf[$i] = "\0";
        }
    }

    /**
     * @param \FFI\CData $ch  CURL*
     * @param \FFI\CData $buf char[257]
     */
    public static function attachErrorBuffer(\FFI\CData $ch, \FFI\CData $buf): void
    {
        self::easySetoptPtr($ch, CurlConstants::CURLOPT_ERRORBUFFER, \FFI::addr($buf[0]));
    }

    /**
     * @param \FFI\CData $buf char[257]
     */
    public static function errorBufferString(\FFI\CData $buf): string
    {
        return (string) \FFI::string($buf);
    }

    /**
     * @return \FFI\CData CURLM*
     */
    public static function multiInit(): \FFI\CData
    {
        $ffi = self::requireCurl();
        $mh = $ffi->curl_multi_init();
        if (null === $mh) {
            throw new \Error('curl_multi_init(): Could not initialize a new cURL multi handle');
        }

        return $mh;
    }

    /**
     * @param \FFI\CData $mh CURLM*
     */
    public static function multiCleanup(\FFI\CData $mh): void
    {
        self::requireCurl()->curl_multi_cleanup($mh);
    }

    /**
     * @param \FFI\CData $mh CURLM*
     * @param \FFI\CData $ch CURL*
     */
    public static function multiAddHandle(\FFI\CData $mh, \FFI\CData $ch): int
    {
        return (int) self::requireCurl()->curl_multi_add_handle($mh, $ch);
    }

    /**
     * @param \FFI\CData $mh CURLM*
     * @param \FFI\CData $ch CURL*
     */
    public static function multiRemoveHandle(\FFI\CData $mh, \FFI\CData $ch): int
    {
        return (int) self::requireCurl()->curl_multi_remove_handle($mh, $ch);
    }

    /**
     * @param \FFI\CData $mh CURLM*
     *
     * @return array{0: int, 1: int} CURLMcode, still_running
     */
    public static function multiPerform(\FFI\CData $mh, int $stillRunning): array
    {
        $ffi = self::requireCurl();
        $running = $ffi->new('int');
        $running->cdata = $stillRunning;
        $rc = (int) $ffi->curl_multi_perform($mh, \FFI::addr($running));

        return [$rc, (int) $running->cdata];
    }

    /**
     * @param \FFI\CData $mh CURLM*
     *
     * @return array{0: int, 1: int} CURLMcode, numfds (-1 on error path for PHP select)
     */
    public static function multiWait(\FFI\CData $mh, int $timeoutMs): array
    {
        $ffi = self::requireCurl();
        $numfds = $ffi->new('int');
        $numfds->cdata = 0;
        $rc = (int) $ffi->curl_multi_wait($mh, null, 0, $timeoutMs, \FFI::addr($numfds));
        if (0 !== $rc) {
            return [$rc, -1];
        }

        return [$rc, (int) $numfds->cdata];
    }

    public static function multiStrerror(int $code): string
    {
        $msg = self::requireCurl()->curl_multi_strerror($code);

        return self::cstringToString($msg);
    }

    /**
     * @param \FFI\CData $mh CURLM*
     *
     * @return array{0: ?array{msg: int, result: int, easy: \FFI\CData}, 1: int}
     *         message payload (null when queue empty) + msgs still queued
     */
    public static function multiInfoRead(\FFI\CData $mh): array
    {
        $ffi = self::requireCurl();
        $queued = $ffi->new('int');
        $queued->cdata = 0;
        $msg = $ffi->curl_multi_info_read($mh, \FFI::addr($queued));
        if (null === $msg) {
            return [null, (int) $queued->cdata];
        }

        return [[
            'msg' => (int) $msg->msg,
            'result' => (int) $msg->data->result,
            'easy' => $msg->easy_handle,
        ], (int) $queued->cdata];
    }

    /**
     * @param \FFI\CData $mh CURLM*
     */
    public static function multiSetoptLong(\FFI\CData $mh, int $option, int $value): int
    {
        return (int) self::requireCurl()->curl_multi_setopt($mh, $option, $value);
    }

    /**
     * @return \FFI\CData FILE*
     */
    public static function fopen(string $path, string $mode): \FFI\CData
    {
        $fp = self::requireLibc()->fopen($path, $mode);
        if (null === $fp) {
            throw new \RuntimeException('fopen() failed for curl write buffer');
        }

        return $fp;
    }

    /**
     * @param \FFI\CData $fp FILE*
     */
    public static function fclose(\FFI\CData $fp): void
    {
        self::requireLibc()->fclose($fp);
    }

    /**
     * @param \FFI\CData $fp FILE*
     */
    public static function rewind(\FFI\CData $fp): void
    {
        self::requireLibc()->rewind($fp);
    }

    /**
     * @param \FFI\CData $fp FILE*
     */
    public static function freadAll(\FFI\CData $fp): string
    {
        $libc = self::requireLibc();
        $libc->rewind($fp);
        $chunks = [];
        $buf = $libc->new('char[16384]');
        while (true) {
            $n = (int) $libc->fread($buf, 1, 16384, $fp);
            if ($n <= 0) {
                break;
            }
            $chunks[] = \FFI::string($buf, $n);
            if ($n < 16384) {
                break;
            }
        }

        return implode('', $chunks);
    }

    /**
     * @param \FFI\CData|null $list curl_slist*
     *
     * @return \FFI\CData curl_slist*
     */
    public static function slistAppend(?\FFI\CData $list, string $header): \FFI\CData
    {
        $next = self::requireCurl()->curl_slist_append($list, $header);
        if (null === $next) {
            throw new \RuntimeException('curl_slist_append() failed');
        }

        return $next;
    }

    /**
     * @param \FFI\CData|null $list curl_slist*
     */
    public static function slistFreeAll(?\FFI\CData $list): void
    {
        if (null === $list) {
            return;
        }
        self::requireCurl()->curl_slist_free_all($list);
    }

    /** @return \FFI */
    private static function requireCurl(): \FFI
    {
        $ffi = self::curlFfi();
        if (null === $ffi) {
            throw new \LogicException('libcurl FFI is not available');
        }

        return $ffi;
    }

    /** @return \FFI */
    private static function requireLibc(): \FFI
    {
        $ffi = self::libcFfi();
        if (null === $ffi) {
            throw new \LogicException('libc FFI is not available');
        }

        return $ffi;
    }

    private static function curlFfi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$curlFfi) {
            return self::$curlFfi;
        }
        if (!self::ffiEnabled()) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef void CURL;
typedef void CURLM;
typedef unsigned long uintptr_t;
struct curl_slist {
    char *data;
    struct curl_slist *next;
};
typedef struct curl_slist curl_slist;
struct CURLMsg {
    int msg;
    CURL *easy_handle;
    union {
        void *whatever;
        int result;
    } data;
};
typedef struct CURLMsg CURLMsg;
CURL *curl_easy_init(void);
void curl_easy_cleanup(CURL *curl);
void curl_easy_reset(CURL *curl);
int curl_easy_pause(CURL *curl, int bitmask);
int curl_easy_upkeep(CURL *curl);
CURL *curl_easy_duphandle(CURL *curl);
int curl_easy_setopt(CURL *curl, int option, ...);
int curl_easy_perform(CURL *curl);
int curl_easy_getinfo(CURL *curl, int info, ...);
const char *curl_easy_strerror(int code);
curl_slist *curl_slist_append(curl_slist *list, const char *data);
void curl_slist_free_all(curl_slist *list);
CURLM *curl_multi_init(void);
int curl_multi_cleanup(CURLM *multi_handle);
int curl_multi_add_handle(CURLM *multi_handle, CURL *curl_handle);
int curl_multi_remove_handle(CURLM *multi_handle, CURL *curl_handle);
int curl_multi_perform(CURLM *multi_handle, int *running_handles);
int curl_multi_wait(CURLM *multi_handle, void *extra_fds, unsigned int extra_nfds, int timeout_ms, int *numfds);
CURLMsg *curl_multi_info_read(CURLM *multi_handle, int *msgs_in_queue);
int curl_multi_setopt(CURLM *multi_handle, int option, ...);
const char *curl_multi_strerror(int code);
CDEF;

        foreach (['libcurl.so.4', 'libcurl.so'] as $lib) {
            try {
                self::$curlFfi = \FFI::cdef($cdef, $lib);

                return self::$curlFfi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function libcFfi(): ?\FFI
    {
        if (self::$ffiUnavailable && null === self::$libcFfi) {
            return null;
        }
        if (null !== self::$libcFfi) {
            return self::$libcFfi;
        }
        if (!self::ffiEnabled()) {
            return null;
        }

        $cdef = <<<'CDEF'
typedef struct _IO_FILE FILE;
FILE *fopen(const char *pathname, const char *mode);
int fclose(FILE *stream);
void rewind(FILE *stream);
size_t fread(void *ptr, size_t size, size_t nmemb, FILE *stream);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$libcFfi = \FFI::cdef($cdef, $lib);

                return self::$libcFfi;
            } catch (\Throwable) {
            }
        }

        if (null === self::$curlFfi) {
            self::$ffiUnavailable = true;
        }

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return \class_exists(\FFI::class, false) || \extension_loaded('ffi');
    }

    /** @param mixed $ptr const char* from FFI (CData or PHP string depending on PHP/FFI) */
    private static function cstringToString(mixed $ptr): string
    {
        if (null === $ptr) {
            return '';
        }
        if (\is_string($ptr)) {
            return $ptr;
        }

        return (string) \FFI::string($ptr);
    }
}
