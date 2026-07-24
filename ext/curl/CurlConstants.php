<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * curl extension constants (php-src ext/curl/curl.stub.php; issue #6999, #3325, #21137, #21336, #22837).
 *
 * CURLOPT_RETURNTRANSFER / CURLOPT_BINARYTRANSFER / CURLOPT_SAFE_UPLOAD are PHP-level
 * options (not forwarded to libcurl). Values match Zend/php-src + libcurl curl.h.
 *
 * PHP 8.4-only option/info names are gated via {@see CurlExtensionPolicy::advertisesPhp84OptionConstants()}
 * so defined() matches Zend 8.2 on the reference profile (#22837).
 */
final class CurlConstants
{
    public const CURLE_OK = 0;
    public const CURLINFO_CONNECT_TIME = 3145733;
    public const CURLINFO_CONTENT_LENGTH_DOWNLOAD = 3145743;
    public const CURLINFO_CONTENT_LENGTH_UPLOAD = 3145744;
    public const CURLINFO_CONTENT_TYPE = 1048594;
    /** CURLINFO_EFFECTIVE_METHOD — libcurl ≥ 7.72 / php-src curl.stub.php (#21883). */
    public const CURLINFO_EFFECTIVE_METHOD = 1048634;
    public const CURLINFO_EFFECTIVE_URL = 1048577;
    public const CURLINFO_FILETIME = 2097166;
    public const CURLINFO_HEADER_OUT = 2;
    public const CURLINFO_HEADER_SIZE = 2097163;
    public const CURLINFO_HTTP_CODE = 2097154;
    public const CURLINFO_LOCAL_IP = 1048617;
    public const CURLINFO_LOCAL_PORT = 2097194;
    public const CURLINFO_NAMELOOKUP_TIME = 3145732;
    /** CURLINFO_POSTTRANSFER_TIME_T — PHP 8.4+ (php-src curl.stub.php; #21336, #22837). */
    public const CURLINFO_POSTTRANSFER_TIME_T = 6291523;
    public const CURLINFO_PRETRANSFER_TIME = 3145734;
    public const CURLINFO_PRIMARY_IP = 1048608;
    public const CURLINFO_PRIMARY_PORT = 2097192;
    public const CURLINFO_REDIRECT_COUNT = 2097172;
    public const CURLINFO_REDIRECT_TIME = 3145747;
    public const CURLINFO_REDIRECT_URL = 1048607;
    /** CURLINFO_REFERER — libcurl CURLINFO_STRING+60 / php-src 8.2+ curl.stub.php (#22837). */
    public const CURLINFO_REFERER = 1048636;
    public const CURLINFO_REQUEST_SIZE = 2097164;
    public const CURLINFO_RESPONSE_CODE = 2097154;
    /** CURLINFO_RETRY_AFTER — libcurl CURLINFO_OFF_T+57 / php-src 8.2+ curl.stub.php (#22837). */
    public const CURLINFO_RETRY_AFTER = 6291513;
    public const CURLINFO_SIZE_DOWNLOAD = 3145736;
    public const CURLINFO_SIZE_UPLOAD = 3145735;
    public const CURLINFO_SPEED_DOWNLOAD = 3145737;
    public const CURLINFO_SPEED_UPLOAD = 3145738;
    public const CURLINFO_SSL_VERIFYRESULT = 2097165;
    public const CURLINFO_STARTTRANSFER_TIME = 3145745;
    public const CURLINFO_TOTAL_TIME = 3145731;
    public const CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE = 30010;
    public const CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE = 30009;
    public const CURLMOPT_MAXCONNECTS = 6;
    public const CURLMOPT_MAX_CONCURRENT_STREAMS = 16;
    public const CURLMOPT_MAX_HOST_CONNECTIONS = 7;
    public const CURLMOPT_MAX_PIPELINE_LENGTH = 8;
    public const CURLMOPT_MAX_TOTAL_CONNECTIONS = 13;
    public const CURLMOPT_PIPELINING = 3;
    /** CURLMSG_DONE — completed transfer message (curl.h / php-src curl.stub.php; #20495). */
    public const CURLMSG_DONE = 1;
    public const CURLM_ADDED_ALREADY = 7;
    public const CURLM_BAD_EASY_HANDLE = 2;
    public const CURLM_BAD_HANDLE = 1;
    public const CURLM_CALL_MULTI_PERFORM = -1;
    public const CURLM_INTERNAL_ERROR = 4;
    public const CURLM_OK = 0;
    public const CURLM_OUT_OF_MEMORY = 3;
    /** libcurl CURLM_UNKNOWN_OPTION — php-src SAVE_CURLM_ERROR on bad setopt (#20495). */
    public const CURLM_UNKNOWN_OPTION = 6;
    public const CURLOPT_ACCEPT_ENCODING = 10102;
    /** CURLOPT_ALTSVC — libcurl STRINGPOINT+287 / php-src 8.2+ curl.stub.php (#22837). */
    public const CURLOPT_ALTSVC = 10287;
    /** CURLOPT_ALTSVC_CTRL — libcurl LONG+286 (#22837). */
    public const CURLOPT_ALTSVC_CTRL = 286;
    public const CURLOPT_AUTOREFERER = 58;
    /** CURLOPT_AWS_SIGV4 — libcurl STRINGPOINT+305 / php-src 8.2+ (#22837). */
    public const CURLOPT_AWS_SIGV4 = 10305;
    /** PHP-only */
    public const CURLOPT_BINARYTRANSFER = 19914;
    public const CURLOPT_BUFFERSIZE = 98;
    public const CURLOPT_CAINFO = 10065;
    /** CURLOPT_CAINFO_BLOB — libcurl BLOB+309 / php-src 8.2+ (#22837). */
    public const CURLOPT_CAINFO_BLOB = 40309;
    public const CURLOPT_CAPATH = 10097;
    public const CURLOPT_CERTINFO = 172;
    public const CURLOPT_CONNECTTIMEOUT = 78;
    public const CURLOPT_CONNECTTIMEOUT_MS = 156;
    public const CURLOPT_CONNECT_ONLY = 141;
    public const CURLOPT_CONNECT_TO = 10243;
    public const CURLOPT_COOKIE = 10022;
    public const CURLOPT_COOKIEFILE = 10031;
    public const CURLOPT_COOKIEJAR = 10082;
    public const CURLOPT_COOKIELIST = 10135;
    public const CURLOPT_COOKIESESSION = 96;
    public const CURLOPT_CUSTOMREQUEST = 10036;
    /** libcurl CURLOPT_DEBUGFUNCTION — PHP 8.4+ (curl.h; php-src curl.stub.php; #21336, #22837). */
    public const CURLOPT_DEBUGFUNCTION = 20094;
    public const CURLOPT_DEFAULT_PROTOCOL = 10238;
    public const CURLOPT_DNS_CACHE_TIMEOUT = 92;
    public const CURLOPT_DNS_SERVERS = 10211;
    public const CURLOPT_ENCODING = 10102;
    public const CURLOPT_EXPECT_100_TIMEOUT_MS = 227;
    public const CURLOPT_FAILONERROR = 45;
    public const CURLOPT_FILE = 10001;
    public const CURLOPT_FILETIME = 69;
    public const CURLOPT_FOLLOWLOCATION = 52;
    public const CURLOPT_FORBID_REUSE = 75;
    public const CURLOPT_FRESH_CONNECT = 74;
    /**
     * CURLOPT_FTP_RESPONSE_TIMEOUT — libcurl LONG+112; Zend 8.2 name
     * (CURLOPT_SERVER_RESPONSE_TIMEOUT is the PHP 8.4 alias; #22837).
     */
    public const CURLOPT_FTP_RESPONSE_TIMEOUT = 112;
    public const CURLOPT_HEADER = 42;
    public const CURLOPT_HEADERFUNCTION = 20079;
    /** CURLOPT_HAPROXYPROTOCOL — libcurl LONG+274 / php-src 8.2+ (#22837). */
    public const CURLOPT_HAPROXYPROTOCOL = 274;
    /** CURLOPT_HSTS — libcurl STRINGPOINT+300 / php-src 8.2+ (#22837). */
    public const CURLOPT_HSTS = 10300;
    /** CURLOPT_HSTS_CTRL — libcurl LONG+299 (#22837). */
    public const CURLOPT_HSTS_CTRL = 299;
    public const CURLOPT_HTTPAUTH = 107;
    public const CURLOPT_HTTPGET = 80;
    public const CURLOPT_HTTPHEADER = 10023;
    public const CURLOPT_HTTPPROXYTUNNEL = 61;
    public const CURLOPT_HTTP_VERSION = 84;
    public const CURLOPT_INFILE = 10009;
    public const CURLOPT_INFILESIZE = 14;
    public const CURLOPT_INTERFACE = 10062;
    public const CURLOPT_IPRESOLVE = 113;
    public const CURLOPT_KEYPASSWD = 10026;
    public const CURLOPT_LOCALPORT = 139;
    public const CURLOPT_LOW_SPEED_LIMIT = 19;
    public const CURLOPT_LOW_SPEED_TIME = 20;
    public const CURLOPT_MAXCONNECTS = 71;
    /** CURLOPT_MAXFILESIZE — libcurl LONG+114 / php-src 8.2+ (#22837). */
    public const CURLOPT_MAXFILESIZE = 114;
    /** CURLOPT_MAXFILESIZE_LARGE — libcurl OFF_T+117 (#22837). */
    public const CURLOPT_MAXFILESIZE_LARGE = 30117;
    public const CURLOPT_MAXREDIRS = 68;
    public const CURLOPT_NOBODY = 44;
    public const CURLOPT_NOPROGRESS = 43;
    public const CURLOPT_PASSWORD = 10174;
    public const CURLOPT_PATH_AS_IS = 234;
    public const CURLOPT_PIPEWAIT = 237;
    public const CURLOPT_PORT = 3;
    public const CURLOPT_POST = 47;
    public const CURLOPT_POSTFIELDS = 10015;
    public const CURLOPT_POSTREDIR = 161;
    public const CURLOPT_PRIVATE = 10103;
    /** libcurl CURLOPT_PREREQFUNCTION — PHP 8.4+ (curl.h; php-src curl.stub.php; #21336, #22837). */
    public const CURLOPT_PREREQFUNCTION = 20312;
    public const CURLOPT_PROGRESSFUNCTION = 20056;
    public const CURLOPT_PROTOCOLS = 181;
    public const CURLOPT_PROXY = 10004;
    public const CURLOPT_PROXYAUTH = 111;
    public const CURLOPT_PROXYPORT = 59;
    public const CURLOPT_PROXYTYPE = 101;
    public const CURLOPT_PROXYUSERPWD = 10006;
    public const CURLOPT_PUT = 54;
    public const CURLOPT_RANGE = 10007;
    public const CURLOPT_READFUNCTION = 20012;
    public const CURLOPT_REDIR_PROTOCOLS = 182;
    public const CURLOPT_REFERER = 10016;
    /** libcurl CURLOPT_SERVER_RESPONSE_TIMEOUT — PHP 8.4+ alias of FTP_RESPONSE_TIMEOUT (#21336, #22837). */
    public const CURLOPT_SERVER_RESPONSE_TIMEOUT = 112;
    public const CURLOPT_RESUME_FROM = 21;
    /** PHP-only — see php-src ext/curl/interface.c */
    public const CURLOPT_RETURNTRANSFER = 19913;
    /** PHP-only historical flag (always true since PHP 5.6) */
    public const CURLOPT_SAFE_UPLOAD = -1;
    public const CURLOPT_SHARE = 10100;
    public const CURLOPT_SSLCERT = 10025;
    public const CURLOPT_SSLCERTTYPE = 10086;
    public const CURLOPT_SSLKEY = 10087;
    public const CURLOPT_SSLKEYTYPE = 10088;
    public const CURLOPT_SSLVERSION = 32;
    public const CURLOPT_SSL_CIPHER_LIST = 10083;
    public const CURLOPT_SSL_VERIFYHOST = 81;
    public const CURLOPT_SSL_VERIFYPEER = 64;
    public const CURLOPT_STDERR = 10037;
    public const CURLOPT_TCP_KEEPALIVE = 213;
    public const CURLOPT_TCP_KEEPIDLE = 214;
    public const CURLOPT_TCP_KEEPINTVL = 215;
    /** libcurl CURLOPT_TCP_KEEPCNT — PHP 8.4+ (curl.h; php-src curl.stub.php; #21336, #22837). */
    public const CURLOPT_TCP_KEEPCNT = 326;
    public const CURLOPT_TCP_NODELAY = 121;
    public const CURLOPT_TIMEOUT = 13;
    public const CURLOPT_TIMEOUT_MS = 155;
    public const CURLOPT_TLS13_CIPHERS = 10276;
    public const CURLOPT_UNIX_SOCKET_PATH = 10231;
    public const CURLOPT_UNRESTRICTED_AUTH = 105;
    public const CURLOPT_UPLOAD = 46;
    public const CURLOPT_URL = 10002;
    public const CURLOPT_USERAGENT = 10018;
    public const CURLOPT_USERNAME = 10173;
    public const CURLOPT_USERPWD = 10005;
    public const CURLOPT_VERBOSE = 41;
    public const CURLOPT_WRITEDATA = 10001;
    public const CURLOPT_WRITEFUNCTION = 20011;
    /** CURLOPT_HTTP_VERSION enum values (curl.h CURL_HTTP_VERSION_*; php-src curl.stub.php; #21336). */
    public const CURL_HTTP_VERSION_NONE = 0;
    public const CURL_HTTP_VERSION_1_0 = 1;
    public const CURL_HTTP_VERSION_1_1 = 2;
    public const CURL_HTTP_VERSION_2 = 3;
    public const CURL_HTTP_VERSION_2_0 = 3;
    public const CURL_HTTP_VERSION_2TLS = 4;
    public const CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE = 5;
    /** CURL_HTTP_VERSION_3 / 3ONLY — PHP 8.4+ (php-src curl.stub.php; #21336, #22837). */
    public const CURL_HTTP_VERSION_3 = 30;
    public const CURL_HTTP_VERSION_3ONLY = 31;
    public const CURLPAUSE_ALL = 5;
    public const CURLPAUSE_CONT = 0;
    /** curl_easy_pause bitmasks (curl/curl.h; php-src curl.stub.php; #20494). */
    public const CURLPAUSE_RECV = 1;
    public const CURLPAUSE_RECV_CONT = 0;
    public const CURLPAUSE_SEND = 4;
    public const CURLPAUSE_SEND_CONT = 0;
    public const CURLSHOPT_NONE = 0;
    public const CURLSHOPT_SHARE = 1;
    public const CURLSHOPT_UNSHARE = 2;
    public const CURL_LOCK_DATA_CONNECT = 5;
    public const CURL_LOCK_DATA_COOKIE = 2;
    public const CURL_LOCK_DATA_DNS = 3;
    /** libcurl curl_lock_data CURL_LOCK_DATA_PSL (curl.h; php-src curl.stub.php; #20530). */
    public const CURL_LOCK_DATA_PSL = 6;
    public const CURL_LOCK_DATA_SSL_SESSION = 4;

    /** CURL_VERSION_* feature-bit constants (curl.h; php-src curl.stub.php; #21337). */
    public const CURL_VERSION_IPV6 = 1;
    public const CURL_VERSION_KERBEROS4 = 2;
    public const CURL_VERSION_SSL = 4;
    public const CURL_VERSION_LIBZ = 8;
    public const CURL_VERSION_NTLM = 16;
    public const CURL_VERSION_GSSNEGOTIATE = 32;
    public const CURL_VERSION_ASYNCHDNS = 128;
    public const CURL_VERSION_SPNEGO = 256;
    public const CURL_VERSION_LARGEFILE = 512;
    public const CURL_VERSION_IDN = 1024;
    public const CURL_VERSION_SSPI = 2048;
    public const CURL_VERSION_CONV = 4096;
    public const CURL_VERSION_TLSAUTH_SRP = 16384;
    public const CURL_VERSION_NTLM_WB = 32768;
    public const CURL_VERSION_HTTP2 = 65536;
    public const CURL_VERSION_GSSAPI = 131072;
    public const CURL_VERSION_KERBEROS5 = 262144;
    public const CURL_VERSION_UNIX_SOCKETS = 524288;
    public const CURL_VERSION_PSL = 1048576;
    public const CURL_VERSION_HTTPS_PROXY = 2097152;
    public const CURL_VERSION_MULTI_SSL = 4194304;
    public const CURL_VERSION_BROTLI = 8388608;
    public const CURL_VERSION_ALTSVC = 16777216;
    public const CURL_VERSION_HTTP3 = 33554432;
    public const CURL_VERSION_ZSTD = 67108864;
    public const CURL_VERSION_UNICODE = 134217728;
    public const CURL_VERSION_HSTS = 268435456;
    public const CURL_VERSION_GSASL = 536870912;

    /** @var array<string, int> CURL_VERSION_* name → bit for feature_list + constant registration. */
    public const VERSION_FEATURE_BITS = [
        'CURL_VERSION_IPV6' => self::CURL_VERSION_IPV6,
        'CURL_VERSION_KERBEROS4' => self::CURL_VERSION_KERBEROS4,
        'CURL_VERSION_SSL' => self::CURL_VERSION_SSL,
        'CURL_VERSION_LIBZ' => self::CURL_VERSION_LIBZ,
        'CURL_VERSION_NTLM' => self::CURL_VERSION_NTLM,
        'CURL_VERSION_GSSNEGOTIATE' => self::CURL_VERSION_GSSNEGOTIATE,
        'CURL_VERSION_ASYNCHDNS' => self::CURL_VERSION_ASYNCHDNS,
        'CURL_VERSION_SPNEGO' => self::CURL_VERSION_SPNEGO,
        'CURL_VERSION_LARGEFILE' => self::CURL_VERSION_LARGEFILE,
        'CURL_VERSION_IDN' => self::CURL_VERSION_IDN,
        'CURL_VERSION_SSPI' => self::CURL_VERSION_SSPI,
        'CURL_VERSION_CONV' => self::CURL_VERSION_CONV,
        'CURL_VERSION_TLSAUTH_SRP' => self::CURL_VERSION_TLSAUTH_SRP,
        'CURL_VERSION_NTLM_WB' => self::CURL_VERSION_NTLM_WB,
        'CURL_VERSION_HTTP2' => self::CURL_VERSION_HTTP2,
        'CURL_VERSION_GSSAPI' => self::CURL_VERSION_GSSAPI,
        'CURL_VERSION_KERBEROS5' => self::CURL_VERSION_KERBEROS5,
        'CURL_VERSION_UNIX_SOCKETS' => self::CURL_VERSION_UNIX_SOCKETS,
        'CURL_VERSION_PSL' => self::CURL_VERSION_PSL,
        'CURL_VERSION_HTTPS_PROXY' => self::CURL_VERSION_HTTPS_PROXY,
        'CURL_VERSION_MULTI_SSL' => self::CURL_VERSION_MULTI_SSL,
        'CURL_VERSION_BROTLI' => self::CURL_VERSION_BROTLI,
        'CURL_VERSION_ALTSVC' => self::CURL_VERSION_ALTSVC,
        'CURL_VERSION_HTTP3' => self::CURL_VERSION_HTTP3,
        'CURL_VERSION_ZSTD' => self::CURL_VERSION_ZSTD,
        'CURL_VERSION_UNICODE' => self::CURL_VERSION_UNICODE,
        'CURL_VERSION_HSTS' => self::CURL_VERSION_HSTS,
        'CURL_VERSION_GSASL' => self::CURL_VERSION_GSASL,
    ];

    /** @var array<int, true> */
    private const EASY_OPTIONS = [
        self::CURLOPT_URL => true,
        self::CURLOPT_RETURNTRANSFER => true,
        self::CURLOPT_BINARYTRANSFER => true,
        self::CURLOPT_SAFE_UPLOAD => true,
        self::CURLOPT_POST => true,
        self::CURLOPT_HTTPHEADER => true,
        self::CURLOPT_SHARE => true,
        self::CURLOPT_NOBODY => true,
        self::CURLOPT_TIMEOUT => true,
        self::CURLOPT_TIMEOUT_MS => true,
        self::CURLOPT_CONNECTTIMEOUT => true,
        self::CURLOPT_CONNECTTIMEOUT_MS => true,
        self::CURLOPT_FOLLOWLOCATION => true,
        self::CURLOPT_MAXREDIRS => true,
        self::CURLOPT_POSTFIELDS => true,
        self::CURLOPT_USERAGENT => true,
        self::CURLOPT_SSL_VERIFYPEER => true,
        self::CURLOPT_SSL_VERIFYHOST => true,
        self::CURLOPT_CAINFO => true,
        self::CURLOPT_CAPATH => true,
        self::CURLOPT_COOKIE => true,
        self::CURLOPT_COOKIEFILE => true,
        self::CURLOPT_COOKIEJAR => true,
        self::CURLOPT_COOKIELIST => true,
        self::CURLOPT_COOKIESESSION => true,
        self::CURLOPT_PROXY => true,
        self::CURLOPT_PROXYPORT => true,
        self::CURLOPT_PROXYUSERPWD => true,
        self::CURLOPT_PROXYTYPE => true,
        self::CURLOPT_HTTPPROXYTUNNEL => true,
        self::CURLOPT_PROXYAUTH => true,
        self::CURLOPT_CUSTOMREQUEST => true,
        self::CURLOPT_HEADER => true,
        self::CURLOPT_HTTPGET => true,
        self::CURLOPT_PUT => true,
        self::CURLOPT_UPLOAD => true,
        self::CURLOPT_FILE => true,
        self::CURLOPT_INFILE => true,
        self::CURLOPT_INFILESIZE => true,
        self::CURLOPT_REFERER => true,
        self::CURLOPT_ENCODING => true,
        self::CURLOPT_ACCEPT_ENCODING => true,
        self::CURLOPT_VERBOSE => true,
        self::CURLOPT_STDERR => true,
        self::CURLOPT_HTTPAUTH => true,
        self::CURLOPT_USERPWD => true,
        self::CURLOPT_USERNAME => true,
        self::CURLOPT_PASSWORD => true,
        self::CURLOPT_HTTP_VERSION => true,
        self::CURLOPT_PROTOCOLS => true,
        self::CURLOPT_REDIR_PROTOCOLS => true,
        self::CURLOPT_FRESH_CONNECT => true,
        self::CURLOPT_FORBID_REUSE => true,
        self::CURLOPT_TCP_NODELAY => true,
        self::CURLOPT_IPRESOLVE => true,
        self::CURLOPT_DNS_CACHE_TIMEOUT => true,
        self::CURLOPT_BUFFERSIZE => true,
        self::CURLOPT_PRIVATE => true,
        self::CURLOPT_NOPROGRESS => true,
        self::CURLOPT_LOW_SPEED_LIMIT => true,
        self::CURLOPT_LOW_SPEED_TIME => true,
        self::CURLOPT_RESUME_FROM => true,
        self::CURLOPT_FAILONERROR => true,
        self::CURLOPT_RANGE => true,
        self::CURLOPT_PORT => true,
        self::CURLOPT_AUTOREFERER => true,
        self::CURLOPT_SSLCERT => true,
        self::CURLOPT_SSLCERTTYPE => true,
        self::CURLOPT_SSLKEY => true,
        self::CURLOPT_SSLKEYTYPE => true,
        self::CURLOPT_KEYPASSWD => true,
        self::CURLOPT_CERTINFO => true,
        self::CURLOPT_INTERFACE => true,
        self::CURLOPT_LOCALPORT => true,
        self::CURLOPT_UNIX_SOCKET_PATH => true,
        self::CURLOPT_POSTREDIR => true,
        self::CURLOPT_UNRESTRICTED_AUTH => true,
        self::CURLOPT_FILETIME => true,
        self::CURLOPT_MAXCONNECTS => true,
        self::CURLOPT_SSLVERSION => true,
        self::CURLOPT_SSL_CIPHER_LIST => true,
        self::CURLOPT_DNS_SERVERS => true,
        self::CURLOPT_DEFAULT_PROTOCOL => true,
        self::CURLOPT_PATH_AS_IS => true,
        self::CURLOPT_PIPEWAIT => true,
        self::CURLOPT_TCP_KEEPALIVE => true,
        self::CURLOPT_TCP_KEEPIDLE => true,
        self::CURLOPT_TCP_KEEPINTVL => true,
        self::CURLOPT_TCP_KEEPCNT => true,
        self::CURLOPT_FTP_RESPONSE_TIMEOUT => true,
        self::CURLOPT_SERVER_RESPONSE_TIMEOUT => true,
        self::CURLOPT_PREREQFUNCTION => true,
        self::CURLOPT_DEBUGFUNCTION => true,
        self::CURLOPT_MAXFILESIZE => true,
        self::CURLOPT_MAXFILESIZE_LARGE => true,
        self::CURLOPT_HSTS => true,
        self::CURLOPT_HSTS_CTRL => true,
        self::CURLOPT_ALTSVC => true,
        self::CURLOPT_ALTSVC_CTRL => true,
        self::CURLOPT_AWS_SIGV4 => true,
        self::CURLOPT_CAINFO_BLOB => true,
        self::CURLOPT_HAPROXYPROTOCOL => true,
        self::CURLOPT_EXPECT_100_TIMEOUT_MS => true,
        self::CURLOPT_CONNECT_TO => true,
        self::CURLOPT_TLS13_CIPHERS => true,
        self::CURLOPT_CONNECT_ONLY => true,
        self::CURLOPT_WRITEFUNCTION => true,
        self::CURLOPT_READFUNCTION => true,
        self::CURLOPT_PROGRESSFUNCTION => true,
        self::CURLOPT_HEADERFUNCTION => true,
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

    /** PHP-only CURLOPT_* — never forwarded to libcurl. */
    public static function isPhpOnlyEasyOption(int $option): bool
    {
        return self::CURLOPT_RETURNTRANSFER === $option
            || self::CURLOPT_BINARYTRANSFER === $option
            || self::CURLOPT_SAFE_UPLOAD === $option;
    }

    /**
     * libcurl CURLOPTTYPE_* encoding (curl.h): long / objectpoint / functionpoint / off_t / blob.
     */
    public static function easyOptionType(int $option): int
    {
        if ($option < 0) {
            return 0;
        }

        return intdiv($option, 10000);
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
            'CURL_LOCK_DATA_PSL' => self::CURL_LOCK_DATA_PSL,
        ];
        foreach (self::VERSION_FEATURE_BITS as $name => $value) {
            $constants[$name] = $value;
        }
        if (CurlExtensionPolicy::advertisesExtension()) {
            foreach (self::httpClientConstants() as $name => $value) {
                $constants[$name] = $value;
            }
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

    /**
     * CURLOPT_* / CURLINFO_* used by common HTTP clients (Guzzle, Symfony HttpClient, …).
     *
     * PHP 8.4-only names are appended only when {@see CurlExtensionPolicy::advertisesPhp84OptionConstants()}
     * (#22837 — withhold phantoms on the 8.2 reference profile).
     *
     * @return array<string, int>
     */
    private static function httpClientConstants(): array
    {
        $constants = [
            'CURLOPT_TIMEOUT' => self::CURLOPT_TIMEOUT,
            'CURLOPT_TIMEOUT_MS' => self::CURLOPT_TIMEOUT_MS,
            'CURLOPT_CONNECTTIMEOUT' => self::CURLOPT_CONNECTTIMEOUT,
            'CURLOPT_CONNECTTIMEOUT_MS' => self::CURLOPT_CONNECTTIMEOUT_MS,
            'CURLOPT_FOLLOWLOCATION' => self::CURLOPT_FOLLOWLOCATION,
            'CURLOPT_MAXREDIRS' => self::CURLOPT_MAXREDIRS,
            'CURLOPT_POSTFIELDS' => self::CURLOPT_POSTFIELDS,
            'CURLOPT_USERAGENT' => self::CURLOPT_USERAGENT,
            'CURLOPT_SSL_VERIFYPEER' => self::CURLOPT_SSL_VERIFYPEER,
            'CURLOPT_SSL_VERIFYHOST' => self::CURLOPT_SSL_VERIFYHOST,
            'CURLOPT_CAINFO' => self::CURLOPT_CAINFO,
            'CURLOPT_CAPATH' => self::CURLOPT_CAPATH,
            'CURLOPT_COOKIE' => self::CURLOPT_COOKIE,
            'CURLOPT_COOKIEFILE' => self::CURLOPT_COOKIEFILE,
            'CURLOPT_COOKIEJAR' => self::CURLOPT_COOKIEJAR,
            'CURLOPT_COOKIELIST' => self::CURLOPT_COOKIELIST,
            'CURLOPT_COOKIESESSION' => self::CURLOPT_COOKIESESSION,
            'CURLOPT_PROXY' => self::CURLOPT_PROXY,
            'CURLOPT_PROXYPORT' => self::CURLOPT_PROXYPORT,
            'CURLOPT_PROXYUSERPWD' => self::CURLOPT_PROXYUSERPWD,
            'CURLOPT_PROXYTYPE' => self::CURLOPT_PROXYTYPE,
            'CURLOPT_HTTPPROXYTUNNEL' => self::CURLOPT_HTTPPROXYTUNNEL,
            'CURLOPT_PROXYAUTH' => self::CURLOPT_PROXYAUTH,
            'CURLOPT_CUSTOMREQUEST' => self::CURLOPT_CUSTOMREQUEST,
            'CURLOPT_HEADER' => self::CURLOPT_HEADER,
            'CURLOPT_HTTPGET' => self::CURLOPT_HTTPGET,
            'CURLOPT_PUT' => self::CURLOPT_PUT,
            'CURLOPT_UPLOAD' => self::CURLOPT_UPLOAD,
            'CURLOPT_FILE' => self::CURLOPT_FILE,
            'CURLOPT_INFILE' => self::CURLOPT_INFILE,
            'CURLOPT_INFILESIZE' => self::CURLOPT_INFILESIZE,
            'CURLOPT_REFERER' => self::CURLOPT_REFERER,
            'CURLOPT_ENCODING' => self::CURLOPT_ENCODING,
            'CURLOPT_ACCEPT_ENCODING' => self::CURLOPT_ACCEPT_ENCODING,
            'CURLOPT_VERBOSE' => self::CURLOPT_VERBOSE,
            'CURLOPT_STDERR' => self::CURLOPT_STDERR,
            'CURLOPT_HTTPAUTH' => self::CURLOPT_HTTPAUTH,
            'CURLOPT_USERPWD' => self::CURLOPT_USERPWD,
            'CURLOPT_USERNAME' => self::CURLOPT_USERNAME,
            'CURLOPT_PASSWORD' => self::CURLOPT_PASSWORD,
            'CURLOPT_HTTP_VERSION' => self::CURLOPT_HTTP_VERSION,
            'CURL_HTTP_VERSION_NONE' => self::CURL_HTTP_VERSION_NONE,
            'CURL_HTTP_VERSION_1_0' => self::CURL_HTTP_VERSION_1_0,
            'CURL_HTTP_VERSION_1_1' => self::CURL_HTTP_VERSION_1_1,
            'CURL_HTTP_VERSION_2' => self::CURL_HTTP_VERSION_2,
            'CURL_HTTP_VERSION_2_0' => self::CURL_HTTP_VERSION_2_0,
            'CURL_HTTP_VERSION_2TLS' => self::CURL_HTTP_VERSION_2TLS,
            'CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE' => self::CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
            'CURLOPT_PROTOCOLS' => self::CURLOPT_PROTOCOLS,
            'CURLOPT_REDIR_PROTOCOLS' => self::CURLOPT_REDIR_PROTOCOLS,
            'CURLOPT_FRESH_CONNECT' => self::CURLOPT_FRESH_CONNECT,
            'CURLOPT_FORBID_REUSE' => self::CURLOPT_FORBID_REUSE,
            'CURLOPT_TCP_NODELAY' => self::CURLOPT_TCP_NODELAY,
            'CURLOPT_IPRESOLVE' => self::CURLOPT_IPRESOLVE,
            'CURLOPT_DNS_CACHE_TIMEOUT' => self::CURLOPT_DNS_CACHE_TIMEOUT,
            'CURLOPT_BUFFERSIZE' => self::CURLOPT_BUFFERSIZE,
            'CURLOPT_PRIVATE' => self::CURLOPT_PRIVATE,
            'CURLOPT_NOPROGRESS' => self::CURLOPT_NOPROGRESS,
            'CURLOPT_LOW_SPEED_LIMIT' => self::CURLOPT_LOW_SPEED_LIMIT,
            'CURLOPT_LOW_SPEED_TIME' => self::CURLOPT_LOW_SPEED_TIME,
            'CURLOPT_RESUME_FROM' => self::CURLOPT_RESUME_FROM,
            'CURLOPT_FAILONERROR' => self::CURLOPT_FAILONERROR,
            'CURLOPT_RANGE' => self::CURLOPT_RANGE,
            'CURLOPT_PORT' => self::CURLOPT_PORT,
            'CURLOPT_AUTOREFERER' => self::CURLOPT_AUTOREFERER,
            'CURLOPT_SAFE_UPLOAD' => self::CURLOPT_SAFE_UPLOAD,
            'CURLOPT_BINARYTRANSFER' => self::CURLOPT_BINARYTRANSFER,
            'CURLOPT_SSLCERT' => self::CURLOPT_SSLCERT,
            'CURLOPT_SSLCERTTYPE' => self::CURLOPT_SSLCERTTYPE,
            'CURLOPT_SSLKEY' => self::CURLOPT_SSLKEY,
            'CURLOPT_SSLKEYTYPE' => self::CURLOPT_SSLKEYTYPE,
            'CURLOPT_KEYPASSWD' => self::CURLOPT_KEYPASSWD,
            'CURLOPT_CERTINFO' => self::CURLOPT_CERTINFO,
            'CURLOPT_INTERFACE' => self::CURLOPT_INTERFACE,
            'CURLOPT_LOCALPORT' => self::CURLOPT_LOCALPORT,
            'CURLOPT_UNIX_SOCKET_PATH' => self::CURLOPT_UNIX_SOCKET_PATH,
            'CURLOPT_POSTREDIR' => self::CURLOPT_POSTREDIR,
            'CURLOPT_UNRESTRICTED_AUTH' => self::CURLOPT_UNRESTRICTED_AUTH,
            'CURLOPT_FILETIME' => self::CURLOPT_FILETIME,
            'CURLOPT_MAXCONNECTS' => self::CURLOPT_MAXCONNECTS,
            'CURLOPT_SSLVERSION' => self::CURLOPT_SSLVERSION,
            'CURLOPT_SSL_CIPHER_LIST' => self::CURLOPT_SSL_CIPHER_LIST,
            'CURLOPT_DNS_SERVERS' => self::CURLOPT_DNS_SERVERS,
            'CURLOPT_DEFAULT_PROTOCOL' => self::CURLOPT_DEFAULT_PROTOCOL,
            'CURLOPT_PATH_AS_IS' => self::CURLOPT_PATH_AS_IS,
            'CURLOPT_PIPEWAIT' => self::CURLOPT_PIPEWAIT,
            'CURLOPT_TCP_KEEPALIVE' => self::CURLOPT_TCP_KEEPALIVE,
            'CURLOPT_TCP_KEEPIDLE' => self::CURLOPT_TCP_KEEPIDLE,
            'CURLOPT_TCP_KEEPINTVL' => self::CURLOPT_TCP_KEEPINTVL,
            'CURLOPT_EXPECT_100_TIMEOUT_MS' => self::CURLOPT_EXPECT_100_TIMEOUT_MS,
            'CURLOPT_CONNECT_TO' => self::CURLOPT_CONNECT_TO,
            'CURLOPT_TLS13_CIPHERS' => self::CURLOPT_TLS13_CIPHERS,
            'CURLOPT_CONNECT_ONLY' => self::CURLOPT_CONNECT_ONLY,
            'CURLOPT_WRITEFUNCTION' => self::CURLOPT_WRITEFUNCTION,
            'CURLOPT_READFUNCTION' => self::CURLOPT_READFUNCTION,
            'CURLOPT_PROGRESSFUNCTION' => self::CURLOPT_PROGRESSFUNCTION,
            'CURLOPT_HEADERFUNCTION' => self::CURLOPT_HEADERFUNCTION,
            // Zend 8.2 surface missing from earlier registration (#22837 / re-#21336).
            'CURLOPT_MAXFILESIZE' => self::CURLOPT_MAXFILESIZE,
            'CURLOPT_MAXFILESIZE_LARGE' => self::CURLOPT_MAXFILESIZE_LARGE,
            'CURLOPT_HSTS' => self::CURLOPT_HSTS,
            'CURLOPT_HSTS_CTRL' => self::CURLOPT_HSTS_CTRL,
            'CURLOPT_ALTSVC' => self::CURLOPT_ALTSVC,
            'CURLOPT_ALTSVC_CTRL' => self::CURLOPT_ALTSVC_CTRL,
            'CURLOPT_AWS_SIGV4' => self::CURLOPT_AWS_SIGV4,
            'CURLOPT_CAINFO_BLOB' => self::CURLOPT_CAINFO_BLOB,
            'CURLOPT_HAPROXYPROTOCOL' => self::CURLOPT_HAPROXYPROTOCOL,
            'CURLOPT_FTP_RESPONSE_TIMEOUT' => self::CURLOPT_FTP_RESPONSE_TIMEOUT,
            'CURLINFO_RESPONSE_CODE' => self::CURLINFO_RESPONSE_CODE,
            'CURLINFO_TOTAL_TIME' => self::CURLINFO_TOTAL_TIME,
            'CURLINFO_CONTENT_TYPE' => self::CURLINFO_CONTENT_TYPE,
            'CURLINFO_EFFECTIVE_METHOD' => self::CURLINFO_EFFECTIVE_METHOD,
            'CURLINFO_SIZE_DOWNLOAD' => self::CURLINFO_SIZE_DOWNLOAD,
            'CURLINFO_PRIMARY_IP' => self::CURLINFO_PRIMARY_IP,
            'CURLINFO_PRIMARY_PORT' => self::CURLINFO_PRIMARY_PORT,
            'CURLINFO_LOCAL_IP' => self::CURLINFO_LOCAL_IP,
            'CURLINFO_LOCAL_PORT' => self::CURLINFO_LOCAL_PORT,
            'CURLINFO_REDIRECT_COUNT' => self::CURLINFO_REDIRECT_COUNT,
            'CURLINFO_REDIRECT_URL' => self::CURLINFO_REDIRECT_URL,
            'CURLINFO_HEADER_SIZE' => self::CURLINFO_HEADER_SIZE,
            'CURLINFO_REQUEST_SIZE' => self::CURLINFO_REQUEST_SIZE,
            'CURLINFO_SSL_VERIFYRESULT' => self::CURLINFO_SSL_VERIFYRESULT,
            'CURLINFO_NAMELOOKUP_TIME' => self::CURLINFO_NAMELOOKUP_TIME,
            'CURLINFO_CONNECT_TIME' => self::CURLINFO_CONNECT_TIME,
            'CURLINFO_STARTTRANSFER_TIME' => self::CURLINFO_STARTTRANSFER_TIME,
            'CURLINFO_PRETRANSFER_TIME' => self::CURLINFO_PRETRANSFER_TIME,
            'CURLINFO_SIZE_UPLOAD' => self::CURLINFO_SIZE_UPLOAD,
            'CURLINFO_SPEED_DOWNLOAD' => self::CURLINFO_SPEED_DOWNLOAD,
            'CURLINFO_SPEED_UPLOAD' => self::CURLINFO_SPEED_UPLOAD,
            'CURLINFO_FILETIME' => self::CURLINFO_FILETIME,
            'CURLINFO_CONTENT_LENGTH_DOWNLOAD' => self::CURLINFO_CONTENT_LENGTH_DOWNLOAD,
            'CURLINFO_CONTENT_LENGTH_UPLOAD' => self::CURLINFO_CONTENT_LENGTH_UPLOAD,
            'CURLINFO_HEADER_OUT' => self::CURLINFO_HEADER_OUT,
            'CURLINFO_REDIRECT_TIME' => self::CURLINFO_REDIRECT_TIME,
            'CURLINFO_REFERER' => self::CURLINFO_REFERER,
            'CURLINFO_RETRY_AFTER' => self::CURLINFO_RETRY_AFTER,
        ];
        if (CurlExtensionPolicy::advertisesPhp84OptionConstants()) {
            $constants['CURL_HTTP_VERSION_3'] = self::CURL_HTTP_VERSION_3;
            $constants['CURL_HTTP_VERSION_3ONLY'] = self::CURL_HTTP_VERSION_3ONLY;
            $constants['CURLINFO_POSTTRANSFER_TIME_T'] = self::CURLINFO_POSTTRANSFER_TIME_T;
            $constants['CURLOPT_TCP_KEEPCNT'] = self::CURLOPT_TCP_KEEPCNT;
            $constants['CURLOPT_SERVER_RESPONSE_TIMEOUT'] = self::CURLOPT_SERVER_RESPONSE_TIMEOUT;
            $constants['CURLOPT_PREREQFUNCTION'] = self::CURLOPT_PREREQFUNCTION;
            $constants['CURLOPT_DEBUGFUNCTION'] = self::CURLOPT_DEBUGFUNCTION;
        }

        return $constants;
    }
}
