<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * Thin libcurl FFI bridge for easy-handle HTTP I/O (php-src ext/curl/interface.c; #3325).
 *
 * Semantics live in {@see VmCurlEasy}; this class only loads libcurl/libc and exposes
 * curl_easy_* / FILE* helpers — no new runtime/ C.
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
        $ffi = self::requireCurl();
        $out = $ffi->new('char*');
        $rc = (int) $ffi->curl_easy_getinfo($ch, $info, \FFI::addr($out));
        if (0 !== $rc || null === $out) {
            return '';
        }

        return (string) \FFI::string($out);
    }

    public static function easyStrerror(int $code): string
    {
        $msg = self::requireCurl()->curl_easy_strerror($code);

        return null === $msg ? '' : (string) \FFI::string($msg);
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
struct curl_slist {
    char *data;
    struct curl_slist *next;
};
typedef struct curl_slist curl_slist;
CURL *curl_easy_init(void);
void curl_easy_cleanup(CURL *curl);
int curl_easy_setopt(CURL *curl, int option, ...);
int curl_easy_perform(CURL *curl);
int curl_easy_getinfo(CURL *curl, int info, ...);
const char *curl_easy_strerror(int code);
curl_slist *curl_slist_append(curl_slist *list, const char *data);
void curl_slist_free_all(curl_slist *list);
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
}
