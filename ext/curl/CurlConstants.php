<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * curl extension constants (php-src ext/curl/curl.stub.php; issue #6999, #3325, #21137, #21336, #22837, #24132).
 *
 * CURLOPT_RETURNTRANSFER / CURLOPT_BINARYTRANSFER / CURLOPT_SAFE_UPLOAD are PHP-level
 * options (not forwarded to libcurl). Values match Zend/php-src + libcurl curl.h.
 *
 * Registration advertises the full Zend 8.2 stub surface (CURLAUTH_*, CURLE_*, CURLALTSVC_*, ...).
 * PHP 8.4-only option/info names are gated via {@see CurlExtensionPolicy::advertisesPhp84OptionConstants()}
 * so defined() matches Zend 8.2 on the reference profile (#22837).
 * Intentional advertisement delta vs Zend: CURLOPT_ERRORBUFFER (FFI path / #25814).
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
    /**
     * CURLINFO_CAINFO — libcurl CURLINFO_STRING+61 / ≥ 7.84 (php-src curl.stub.php; #23899).
     * Path of the CA certificate bundle used for the previous transfer.
     */
    public const CURLINFO_CAINFO = 1048637;
    /**
     * CURLINFO_CAPATH — libcurl CURLINFO_STRING+62 / ≥ 7.84 (php-src curl.stub.php; #23899).
     * Directory holding CA certificates used for the previous transfer.
     */
    public const CURLINFO_CAPATH = 1048638;
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
    /**
     * CURLOPT_ERRORBUFFER — libcurl OBJECTPOINT+10; php-src attaches ch->err.str
     * (ext/curl/interface.c _php_curl_set_default_options; #25814).
     */
    public const CURLOPT_ERRORBUFFER = 10010;
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
    /**
     * CURLOPT_UPKEEP_INTERVAL_MS — libcurl LONG+281 / PHP 8.2+ (curl.h; php-src curl.stub.php; #26263, #23899).
     * Connection upkeep interval for curl_upkeep() / curl_easy_upkeep (default 60000 ms).
     */
    public const CURLOPT_UPKEEP_INTERVAL_MS = 281;
    /**
     * CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS — libcurl LONG+271 (curl.h; php-src curl.stub.php; #23899).
     * Head-start timeout for Happy Eyeballs (IPv4/IPv6 racing).
     */
    public const CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS = 271;
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

    // Zend 8.2.32 stub surface previously unregistered (#24132 — CURLAUTH_, CURLE_, CURLALTSVC_, ...).
    public const CURLALTSVC_H1 = 8;
    public const CURLALTSVC_H2 = 16;
    public const CURLALTSVC_H3 = 32;
    public const CURLALTSVC_READONLYFILE = 4;
    public const CURLAUTH_ANY = -17;
    public const CURLAUTH_ANYSAFE = -18;
    public const CURLAUTH_AWS_SIGV4 = 128;
    public const CURLAUTH_BASIC = 1;
    public const CURLAUTH_BEARER = 64;
    public const CURLAUTH_DIGEST = 2;
    public const CURLAUTH_DIGEST_IE = 16;
    public const CURLAUTH_GSSAPI = 4;
    public const CURLAUTH_GSSNEGOTIATE = 4;
    public const CURLAUTH_NEGOTIATE = 4;
    public const CURLAUTH_NONE = 0;
    public const CURLAUTH_NTLM = 8;
    public const CURLAUTH_NTLM_WB = 32;
    public const CURLAUTH_ONLY = 2147483648;
    public const CURLE_ABORTED_BY_CALLBACK = 42;
    public const CURLE_BAD_CALLING_ORDER = 44;
    public const CURLE_BAD_CONTENT_ENCODING = 61;
    public const CURLE_BAD_DOWNLOAD_RESUME = 36;
    public const CURLE_BAD_FUNCTION_ARGUMENT = 43;
    public const CURLE_BAD_PASSWORD_ENTERED = 46;
    public const CURLE_COULDNT_CONNECT = 7;
    public const CURLE_COULDNT_RESOLVE_HOST = 6;
    public const CURLE_COULDNT_RESOLVE_PROXY = 5;
    public const CURLE_FAILED_INIT = 2;
    public const CURLE_FILESIZE_EXCEEDED = 63;
    public const CURLE_FILE_COULDNT_READ_FILE = 37;
    public const CURLE_FTP_ACCESS_DENIED = 9;
    public const CURLE_FTP_BAD_DOWNLOAD_RESUME = 36;
    public const CURLE_FTP_CANT_GET_HOST = 15;
    public const CURLE_FTP_CANT_RECONNECT = 16;
    public const CURLE_FTP_COULDNT_GET_SIZE = 32;
    public const CURLE_FTP_COULDNT_RETR_FILE = 19;
    public const CURLE_FTP_COULDNT_SET_ASCII = 29;
    public const CURLE_FTP_COULDNT_SET_BINARY = 17;
    public const CURLE_FTP_COULDNT_STOR_FILE = 25;
    public const CURLE_FTP_COULDNT_USE_REST = 31;
    public const CURLE_FTP_PARTIAL_FILE = 18;
    public const CURLE_FTP_PORT_FAILED = 30;
    public const CURLE_FTP_QUOTE_ERROR = 21;
    public const CURLE_FTP_SSL_FAILED = 64;
    public const CURLE_FTP_USER_PASSWORD_INCORRECT = 10;
    public const CURLE_FTP_WEIRD_227_FORMAT = 14;
    public const CURLE_FTP_WEIRD_PASS_REPLY = 11;
    public const CURLE_FTP_WEIRD_PASV_REPLY = 13;
    public const CURLE_FTP_WEIRD_SERVER_REPLY = 8;
    public const CURLE_FTP_WEIRD_USER_REPLY = 12;
    public const CURLE_FTP_WRITE_ERROR = 20;
    public const CURLE_FUNCTION_NOT_FOUND = 41;
    public const CURLE_GOT_NOTHING = 52;
    public const CURLE_HTTP_NOT_FOUND = 22;
    public const CURLE_HTTP_PORT_FAILED = 45;
    public const CURLE_HTTP_POST_ERROR = 34;
    public const CURLE_HTTP_RANGE_ERROR = 33;
    public const CURLE_HTTP_RETURNED_ERROR = 22;
    public const CURLE_LDAP_CANNOT_BIND = 38;
    public const CURLE_LDAP_INVALID_URL = 62;
    public const CURLE_LDAP_SEARCH_FAILED = 39;
    public const CURLE_LIBRARY_NOT_FOUND = 40;
    public const CURLE_MALFORMAT_USER = 24;
    public const CURLE_OBSOLETE = 50;
    public const CURLE_OPERATION_TIMEDOUT = 28;
    public const CURLE_OPERATION_TIMEOUTED = 28;
    public const CURLE_OUT_OF_MEMORY = 27;
    public const CURLE_PARTIAL_FILE = 18;
    public const CURLE_PROXY = 97;
    public const CURLE_READ_ERROR = 26;
    public const CURLE_RECV_ERROR = 56;
    public const CURLE_SEND_ERROR = 55;
    public const CURLE_SHARE_IN_USE = 57;
    public const CURLE_SSH = 79;
    public const CURLE_SSL_CACERT = 60;
    public const CURLE_SSL_CACERT_BADFILE = 77;
    public const CURLE_SSL_CERTPROBLEM = 58;
    public const CURLE_SSL_CIPHER = 59;
    public const CURLE_SSL_CONNECT_ERROR = 35;
    public const CURLE_SSL_ENGINE_NOTFOUND = 53;
    public const CURLE_SSL_ENGINE_SETFAILED = 54;
    public const CURLE_SSL_PEER_CERTIFICATE = 60;
    public const CURLE_SSL_PINNEDPUBKEYNOTMATCH = 90;
    public const CURLE_TELNET_OPTION_SYNTAX = 49;
    public const CURLE_TOO_MANY_REDIRECTS = 47;
    public const CURLE_UNKNOWN_TELNET_OPTION = 48;
    public const CURLE_UNSUPPORTED_PROTOCOL = 1;
    public const CURLE_URL_MALFORMAT = 3;
    public const CURLE_URL_MALFORMAT_USER = 4;
    public const CURLE_WEIRD_SERVER_REPLY = 8;
    public const CURLE_WRITE_ERROR = 23;
    public const CURLFTPAUTH_DEFAULT = 0;
    public const CURLFTPAUTH_SSL = 1;
    public const CURLFTPAUTH_TLS = 2;
    public const CURLFTPMETHOD_DEFAULT = 0;
    public const CURLFTPMETHOD_MULTICWD = 1;
    public const CURLFTPMETHOD_NOCWD = 2;
    public const CURLFTPMETHOD_SINGLECWD = 3;
    public const CURLFTPSSL_ALL = 3;
    public const CURLFTPSSL_CCC_ACTIVE = 2;
    public const CURLFTPSSL_CCC_NONE = 0;
    public const CURLFTPSSL_CCC_PASSIVE = 1;
    public const CURLFTPSSL_CONTROL = 2;
    public const CURLFTPSSL_NONE = 0;
    public const CURLFTPSSL_TRY = 1;
    public const CURLFTP_CREATE_DIR = 1;
    public const CURLFTP_CREATE_DIR_NONE = 0;
    public const CURLFTP_CREATE_DIR_RETRY = 2;
    public const CURLGSSAPI_DELEGATION_FLAG = 2;
    public const CURLGSSAPI_DELEGATION_POLICY_FLAG = 1;
    public const CURLHEADER_SEPARATE = 1;
    public const CURLHEADER_UNIFIED = 0;
    public const CURLHSTS_ENABLE = 1;
    public const CURLHSTS_READONLYFILE = 2;
    public const CURLINFO_APPCONNECT_TIME = 3145761;
    public const CURLINFO_APPCONNECT_TIME_T = 6291512;
    public const CURLINFO_CERTINFO = 4194338;
    public const CURLINFO_CONDITION_UNMET = 2097187;
    public const CURLINFO_CONNECT_TIME_T = 6291508;
    public const CURLINFO_CONTENT_LENGTH_DOWNLOAD_T = 6291471;
    public const CURLINFO_CONTENT_LENGTH_UPLOAD_T = 6291472;
    public const CURLINFO_COOKIELIST = 4194332;
    public const CURLINFO_FILETIME_T = 6291470;
    public const CURLINFO_FTP_ENTRY_PATH = 1048606;
    public const CURLINFO_HTTPAUTH_AVAIL = 2097175;
    public const CURLINFO_HTTP_CONNECTCODE = 2097174;
    public const CURLINFO_HTTP_VERSION = 2097198;
    public const CURLINFO_LASTONE = 62;
    public const CURLINFO_NAMELOOKUP_TIME_T = 6291507;
    public const CURLINFO_NUM_CONNECTS = 2097178;
    public const CURLINFO_OS_ERRNO = 2097177;
    public const CURLINFO_PRETRANSFER_TIME_T = 6291509;
    public const CURLINFO_PRIVATE = 1048597;
    public const CURLINFO_PROTOCOL = 2097200;
    public const CURLINFO_PROXYAUTH_AVAIL = 2097176;
    public const CURLINFO_PROXY_ERROR = 2097211;
    public const CURLINFO_PROXY_SSL_VERIFYRESULT = 2097199;
    public const CURLINFO_REDIRECT_TIME_T = 6291511;
    public const CURLINFO_RTSP_CLIENT_CSEQ = 2097189;
    public const CURLINFO_RTSP_CSEQ_RECV = 2097191;
    public const CURLINFO_RTSP_SERVER_CSEQ = 2097190;
    public const CURLINFO_RTSP_SESSION_ID = 1048612;
    public const CURLINFO_SCHEME = 1048625;
    public const CURLINFO_SIZE_DOWNLOAD_T = 6291464;
    public const CURLINFO_SIZE_UPLOAD_T = 6291463;
    public const CURLINFO_SPEED_DOWNLOAD_T = 6291465;
    public const CURLINFO_SPEED_UPLOAD_T = 6291466;
    public const CURLINFO_SSL_ENGINES = 4194331;
    public const CURLINFO_STARTTRANSFER_TIME_T = 6291510;
    public const CURLINFO_TOTAL_TIME_T = 6291506;
    public const CURLMOPT_PUSHFUNCTION = 20014;
    public const CURLOPT_ABSTRACT_UNIX_SOCKET = 10264;
    public const CURLOPT_ACCEPTTIMEOUT_MS = 212;
    public const CURLOPT_ADDRESS_SCOPE = 171;
    public const CURLOPT_APPEND = 50;
    public const CURLOPT_CRLF = 27;
    public const CURLOPT_CRLFILE = 10169;
    public const CURLOPT_DIRLISTONLY = 48;
    public const CURLOPT_DISALLOW_USERNAME_IN_URL = 278;
    public const CURLOPT_DNS_INTERFACE = 10221;
    public const CURLOPT_DNS_LOCAL_IP4 = 10222;
    public const CURLOPT_DNS_LOCAL_IP6 = 10223;
    public const CURLOPT_DNS_SHUFFLE_ADDRESSES = 275;
    public const CURLOPT_DNS_USE_GLOBAL_CACHE = 91;
    public const CURLOPT_DOH_SSL_VERIFYHOST = 307;
    public const CURLOPT_DOH_SSL_VERIFYPEER = 306;
    public const CURLOPT_DOH_SSL_VERIFYSTATUS = 308;
    public const CURLOPT_DOH_URL = 10279;
    public const CURLOPT_EGDSOCKET = 10077;
    public const CURLOPT_FNMATCH_FUNCTION = 20200;
    public const CURLOPT_FTPAPPEND = 50;
    public const CURLOPT_FTPLISTONLY = 48;
    public const CURLOPT_FTPPORT = 10017;
    public const CURLOPT_FTPSSLAUTH = 129;
    public const CURLOPT_FTP_ACCOUNT = 10134;
    public const CURLOPT_FTP_ALTERNATIVE_TO_USER = 10147;
    public const CURLOPT_FTP_CREATE_MISSING_DIRS = 110;
    public const CURLOPT_FTP_FILEMETHOD = 138;
    public const CURLOPT_FTP_SKIP_PASV_IP = 137;
    public const CURLOPT_FTP_SSL = 119;
    public const CURLOPT_FTP_SSL_CCC = 154;
    public const CURLOPT_FTP_USE_EPRT = 106;
    public const CURLOPT_FTP_USE_EPSV = 85;
    public const CURLOPT_FTP_USE_PRET = 188;
    public const CURLOPT_GSSAPI_DELEGATION = 210;
    public const CURLOPT_HEADEROPT = 229;
    public const CURLOPT_HTTP09_ALLOWED = 285;
    public const CURLOPT_HTTP200ALIASES = 10104;
    public const CURLOPT_HTTP_CONTENT_DECODING = 158;
    public const CURLOPT_HTTP_TRANSFER_DECODING = 157;
    public const CURLOPT_IGNORE_CONTENT_LENGTH = 136;
    public const CURLOPT_ISSUERCERT = 10170;
    public const CURLOPT_ISSUERCERT_BLOB = 40295;
    public const CURLOPT_KEEP_SENDING_ON_ERROR = 245;
    public const CURLOPT_KRB4LEVEL = 10063;
    public const CURLOPT_KRBLEVEL = 10063;
    public const CURLOPT_LOCALPORTRANGE = 140;
    public const CURLOPT_LOGIN_OPTIONS = 10224;
    public const CURLOPT_MAIL_AUTH = 10217;
    public const CURLOPT_MAIL_FROM = 10186;
    public const CURLOPT_MAIL_RCPT = 10187;
    public const CURLOPT_MAIL_RCPT_ALLLOWFAILS = 290;
    public const CURLOPT_MAXAGE_CONN = 288;
    public const CURLOPT_MAXLIFETIME_CONN = 314;
    public const CURLOPT_MAX_RECV_SPEED_LARGE = 30146;
    public const CURLOPT_MAX_SEND_SPEED_LARGE = 30145;
    public const CURLOPT_NETRC = 51;
    public const CURLOPT_NETRC_FILE = 10118;
    public const CURLOPT_NEW_DIRECTORY_PERMS = 160;
    public const CURLOPT_NEW_FILE_PERMS = 159;
    public const CURLOPT_NOPROXY = 10177;
    public const CURLOPT_NOSIGNAL = 99;
    public const CURLOPT_PINNEDPUBLICKEY = 10230;
    public const CURLOPT_POSTQUOTE = 10039;
    public const CURLOPT_PREQUOTE = 10093;
    public const CURLOPT_PRE_PROXY = 10262;
    public const CURLOPT_PROXYHEADER = 10228;
    public const CURLOPT_PROXYPASSWORD = 10176;
    public const CURLOPT_PROXYUSERNAME = 10175;
    public const CURLOPT_PROXY_CAINFO = 10246;
    public const CURLOPT_PROXY_CAINFO_BLOB = 40310;
    public const CURLOPT_PROXY_CAPATH = 10247;
    public const CURLOPT_PROXY_CRLFILE = 10260;
    public const CURLOPT_PROXY_ISSUERCERT = 10296;
    public const CURLOPT_PROXY_ISSUERCERT_BLOB = 40297;
    public const CURLOPT_PROXY_KEYPASSWD = 10258;
    public const CURLOPT_PROXY_PINNEDPUBLICKEY = 10263;
    public const CURLOPT_PROXY_SERVICE_NAME = 10235;
    public const CURLOPT_PROXY_SSLCERT = 10254;
    public const CURLOPT_PROXY_SSLCERTTYPE = 10255;
    public const CURLOPT_PROXY_SSLCERT_BLOB = 40293;
    public const CURLOPT_PROXY_SSLKEY = 10256;
    public const CURLOPT_PROXY_SSLKEYTYPE = 10257;
    public const CURLOPT_PROXY_SSLKEY_BLOB = 40294;
    public const CURLOPT_PROXY_SSLVERSION = 250;
    public const CURLOPT_PROXY_SSL_CIPHER_LIST = 10259;
    public const CURLOPT_PROXY_SSL_OPTIONS = 261;
    public const CURLOPT_PROXY_SSL_VERIFYHOST = 249;
    public const CURLOPT_PROXY_SSL_VERIFYPEER = 248;
    public const CURLOPT_PROXY_TLS13_CIPHERS = 10277;
    public const CURLOPT_PROXY_TLSAUTH_PASSWORD = 10252;
    public const CURLOPT_PROXY_TLSAUTH_TYPE = 10253;
    public const CURLOPT_PROXY_TLSAUTH_USERNAME = 10251;
    public const CURLOPT_PROXY_TRANSFER_MODE = 166;
    public const CURLOPT_QUOTE = 10028;
    public const CURLOPT_RANDOM_FILE = 10076;
    public const CURLOPT_READDATA = 10009;
    public const CURLOPT_REQUEST_TARGET = 10266;
    public const CURLOPT_RESOLVE = 10203;
    public const CURLOPT_RTSP_CLIENT_CSEQ = 193;
    public const CURLOPT_RTSP_REQUEST = 189;
    public const CURLOPT_RTSP_SERVER_CSEQ = 194;
    public const CURLOPT_RTSP_SESSION_ID = 10190;
    public const CURLOPT_RTSP_STREAM_URI = 10191;
    public const CURLOPT_RTSP_TRANSPORT = 10192;
    public const CURLOPT_SASL_AUTHZID = 10289;
    public const CURLOPT_SASL_IR = 218;
    public const CURLOPT_SERVICE_NAME = 10236;
    public const CURLOPT_SOCKS5_AUTH = 267;
    public const CURLOPT_SOCKS5_GSSAPI_NEC = 180;
    public const CURLOPT_SOCKS5_GSSAPI_SERVICE = 10179;
    public const CURLOPT_SSH_AUTH_TYPES = 151;
    public const CURLOPT_SSH_COMPRESSION = 268;
    public const CURLOPT_SSH_HOST_PUBLIC_KEY_MD5 = 10162;
    public const CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256 = 10311;
    public const CURLOPT_SSH_KNOWNHOSTS = 10183;
    public const CURLOPT_SSH_PRIVATE_KEYFILE = 10153;
    public const CURLOPT_SSH_PUBLIC_KEYFILE = 10152;
    public const CURLOPT_SSLCERTPASSWD = 10026;
    public const CURLOPT_SSLCERT_BLOB = 40291;
    public const CURLOPT_SSLENGINE = 10089;
    public const CURLOPT_SSLENGINE_DEFAULT = 90;
    public const CURLOPT_SSLKEYPASSWD = 10026;
    public const CURLOPT_SSLKEY_BLOB = 40292;
    public const CURLOPT_SSL_EC_CURVES = 10298;
    public const CURLOPT_SSL_ENABLE_ALPN = 226;
    public const CURLOPT_SSL_ENABLE_NPN = 225;
    public const CURLOPT_SSL_FALSESTART = 233;
    public const CURLOPT_SSL_OPTIONS = 216;
    public const CURLOPT_SSL_SESSIONID_CACHE = 150;
    public const CURLOPT_SSL_VERIFYSTATUS = 232;
    public const CURLOPT_STREAM_WEIGHT = 239;
    public const CURLOPT_SUPPRESS_CONNECT_HEADERS = 265;
    public const CURLOPT_TCP_FASTOPEN = 244;
    public const CURLOPT_TELNETOPTIONS = 10070;
    public const CURLOPT_TFTP_BLKSIZE = 178;
    public const CURLOPT_TFTP_NO_OPTIONS = 242;
    public const CURLOPT_TIMECONDITION = 33;
    public const CURLOPT_TIMEVALUE = 34;
    public const CURLOPT_TIMEVALUE_LARGE = 30270;
    public const CURLOPT_TLSAUTH_PASSWORD = 10205;
    public const CURLOPT_TLSAUTH_TYPE = 10206;
    public const CURLOPT_TLSAUTH_USERNAME = 10204;
    public const CURLOPT_TRANSFERTEXT = 53;
    public const CURLOPT_TRANSFER_ENCODING = 207;
    public const CURLOPT_UPLOAD_BUFFERSIZE = 280;
    public const CURLOPT_USE_SSL = 119;
    public const CURLOPT_WILDCARDMATCH = 197;
    public const CURLOPT_WRITEHEADER = 10029;
    public const CURLOPT_XFERINFOFUNCTION = 20219;
    public const CURLOPT_XOAUTH2_BEARER = 10220;
    public const CURLPIPE_HTTP1 = 1;
    public const CURLPIPE_MULTIPLEX = 2;
    public const CURLPIPE_NOTHING = 0;
    public const CURLPROTO_ALL = -1;
    public const CURLPROTO_DICT = 512;
    public const CURLPROTO_FILE = 1024;
    public const CURLPROTO_FTP = 4;
    public const CURLPROTO_FTPS = 8;
    public const CURLPROTO_GOPHER = 33554432;
    public const CURLPROTO_HTTP = 1;
    public const CURLPROTO_HTTPS = 2;
    public const CURLPROTO_IMAP = 4096;
    public const CURLPROTO_IMAPS = 8192;
    public const CURLPROTO_LDAP = 128;
    public const CURLPROTO_LDAPS = 256;
    public const CURLPROTO_MQTT = 268435456;
    public const CURLPROTO_POP3 = 16384;
    public const CURLPROTO_POP3S = 32768;
    public const CURLPROTO_RTMP = 524288;
    public const CURLPROTO_RTMPE = 2097152;
    public const CURLPROTO_RTMPS = 8388608;
    public const CURLPROTO_RTMPT = 1048576;
    public const CURLPROTO_RTMPTE = 4194304;
    public const CURLPROTO_RTMPTS = 16777216;
    public const CURLPROTO_RTSP = 262144;
    public const CURLPROTO_SCP = 16;
    public const CURLPROTO_SFTP = 32;
    public const CURLPROTO_SMB = 67108864;
    public const CURLPROTO_SMBS = 134217728;
    public const CURLPROTO_SMTP = 65536;
    public const CURLPROTO_SMTPS = 131072;
    public const CURLPROTO_TELNET = 64;
    public const CURLPROTO_TFTP = 2048;
    public const CURLPROXY_HTTP = 0;
    public const CURLPROXY_HTTPS = 2;
    public const CURLPROXY_HTTP_1_0 = 1;
    public const CURLPROXY_SOCKS4 = 4;
    public const CURLPROXY_SOCKS4A = 6;
    public const CURLPROXY_SOCKS5 = 5;
    public const CURLPROXY_SOCKS5_HOSTNAME = 7;
    public const CURLPX_BAD_ADDRESS_TYPE = 1;
    public const CURLPX_BAD_VERSION = 2;
    public const CURLPX_CLOSED = 3;
    public const CURLPX_GSSAPI = 4;
    public const CURLPX_GSSAPI_PERMSG = 5;
    public const CURLPX_GSSAPI_PROTECTION = 6;
    public const CURLPX_IDENTD = 7;
    public const CURLPX_IDENTD_DIFFER = 8;
    public const CURLPX_LONG_HOSTNAME = 9;
    public const CURLPX_LONG_PASSWD = 10;
    public const CURLPX_LONG_USER = 11;
    public const CURLPX_NO_AUTH = 12;
    public const CURLPX_OK = 0;
    public const CURLPX_RECV_ADDRESS = 13;
    public const CURLPX_RECV_AUTH = 14;
    public const CURLPX_RECV_CONNECT = 15;
    public const CURLPX_RECV_REQACK = 16;
    public const CURLPX_REPLY_ADDRESS_TYPE_NOT_SUPPORTED = 17;
    public const CURLPX_REPLY_COMMAND_NOT_SUPPORTED = 18;
    public const CURLPX_REPLY_CONNECTION_REFUSED = 19;
    public const CURLPX_REPLY_GENERAL_SERVER_FAILURE = 20;
    public const CURLPX_REPLY_HOST_UNREACHABLE = 21;
    public const CURLPX_REPLY_NETWORK_UNREACHABLE = 22;
    public const CURLPX_REPLY_NOT_ALLOWED = 23;
    public const CURLPX_REPLY_TTL_EXPIRED = 24;
    public const CURLPX_REPLY_UNASSIGNED = 25;
    public const CURLPX_REQUEST_FAILED = 26;
    public const CURLPX_RESOLVE_HOST = 27;
    public const CURLPX_SEND_AUTH = 28;
    public const CURLPX_SEND_CONNECT = 29;
    public const CURLPX_SEND_REQUEST = 30;
    public const CURLPX_UNKNOWN_FAIL = 31;
    public const CURLPX_UNKNOWN_MODE = 32;
    public const CURLPX_USER_REJECTED = 33;
    public const CURLSSH_AUTH_AGENT = 16;
    public const CURLSSH_AUTH_ANY = -1;
    public const CURLSSH_AUTH_DEFAULT = -1;
    public const CURLSSH_AUTH_GSSAPI = 32;
    public const CURLSSH_AUTH_HOST = 4;
    public const CURLSSH_AUTH_KEYBOARD = 8;
    public const CURLSSH_AUTH_NONE = 0;
    public const CURLSSH_AUTH_PASSWORD = 2;
    public const CURLSSH_AUTH_PUBLICKEY = 1;
    public const CURLSSLOPT_ALLOW_BEAST = 1;
    public const CURLSSLOPT_AUTO_CLIENT_CERT = 32;
    public const CURLSSLOPT_NATIVE_CA = 16;
    public const CURLSSLOPT_NO_PARTIALCHAIN = 4;
    public const CURLSSLOPT_NO_REVOKE = 2;
    public const CURLSSLOPT_REVOKE_BEST_EFFORT = 8;
    public const CURLUSESSL_ALL = 3;
    public const CURLUSESSL_CONTROL = 2;
    public const CURLUSESSL_NONE = 0;
    public const CURLUSESSL_TRY = 1;
    public const CURLVERSION_NOW = 10;
    public const CURL_FNMATCHFUNC_FAIL = 2;
    public const CURL_FNMATCHFUNC_MATCH = 0;
    public const CURL_FNMATCHFUNC_NOMATCH = 1;
    public const CURL_IPRESOLVE_V4 = 1;
    public const CURL_IPRESOLVE_V6 = 2;
    public const CURL_IPRESOLVE_WHATEVER = 0;
    public const CURL_MAX_READ_SIZE = 10485760;
    public const CURL_NETRC_IGNORED = 0;
    public const CURL_NETRC_OPTIONAL = 1;
    public const CURL_NETRC_REQUIRED = 2;
    public const CURL_PUSH_DENY = 1;
    public const CURL_PUSH_OK = 0;
    public const CURL_READFUNC_PAUSE = 268435457;
    public const CURL_REDIR_POST_301 = 1;
    public const CURL_REDIR_POST_302 = 2;
    public const CURL_REDIR_POST_303 = 4;
    public const CURL_REDIR_POST_ALL = 7;
    public const CURL_RTSPREQ_ANNOUNCE = 3;
    public const CURL_RTSPREQ_DESCRIBE = 2;
    public const CURL_RTSPREQ_GET_PARAMETER = 8;
    public const CURL_RTSPREQ_OPTIONS = 1;
    public const CURL_RTSPREQ_PAUSE = 6;
    public const CURL_RTSPREQ_PLAY = 5;
    public const CURL_RTSPREQ_RECEIVE = 11;
    public const CURL_RTSPREQ_RECORD = 10;
    public const CURL_RTSPREQ_SETUP = 4;
    public const CURL_RTSPREQ_SET_PARAMETER = 9;
    public const CURL_RTSPREQ_TEARDOWN = 7;
    public const CURL_SSLVERSION_DEFAULT = 0;
    public const CURL_SSLVERSION_MAX_DEFAULT = 65536;
    public const CURL_SSLVERSION_MAX_NONE = 0;
    public const CURL_SSLVERSION_MAX_TLSv1_0 = 262144;
    public const CURL_SSLVERSION_MAX_TLSv1_1 = 327680;
    public const CURL_SSLVERSION_MAX_TLSv1_2 = 393216;
    public const CURL_SSLVERSION_MAX_TLSv1_3 = 458752;
    public const CURL_SSLVERSION_SSLv2 = 2;
    public const CURL_SSLVERSION_SSLv3 = 3;
    public const CURL_SSLVERSION_TLSv1 = 1;
    public const CURL_SSLVERSION_TLSv1_0 = 4;
    public const CURL_SSLVERSION_TLSv1_1 = 5;
    public const CURL_SSLVERSION_TLSv1_2 = 6;
    public const CURL_SSLVERSION_TLSv1_3 = 7;
    public const CURL_TIMECOND_IFMODSINCE = 1;
    public const CURL_TIMECOND_IFUNMODSINCE = 2;
    public const CURL_TIMECOND_LASTMOD = 3;
    public const CURL_TIMECOND_NONE = 0;
    public const CURL_TLSAUTH_SRP = 1;
    public const CURL_VERSION_CURLDEBUG = 8192;
    public const CURL_VERSION_DEBUG = 64;
    public const CURL_WRITEFUNC_PAUSE = 268435457;

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
        self::CURLOPT_ERRORBUFFER => true,
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
        self::CURLOPT_UPKEEP_INTERVAL_MS => true,
        self::CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS => true,
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

    /**
     * PHP 8.4-only CURLOPT/CURLINFO names (php-src curl.stub.php; #21336, #22837, #24132).
     *
     * Withheld on the 8.2 reference profile so defined() matches Zend 8.2.
     *
     * @var list<string>
     */
    private const PHP84_OPTION_CONSTANT_NAMES = [
        'CURL_HTTP_VERSION_3',
        'CURL_HTTP_VERSION_3ONLY',
        'CURLINFO_POSTTRANSFER_TIME_T',
        'CURLINFO_CAINFO',
        'CURLINFO_CAPATH',
        'CURLOPT_TCP_KEEPCNT',
        'CURLOPT_SERVER_RESPONSE_TIMEOUT',
        'CURLOPT_PREREQFUNCTION',
        'CURLOPT_DEBUGFUNCTION',
    ];

    /**
     * Class consts used by FFI helpers but not present on Zend 8.2 curl.stub.php.
     * CURLOPT_ERRORBUFFER is advertised anyway (#25814 compliance); CURLOPT_WRITEDATA /
     * CURLM_UNKNOWN_OPTION stay internal-only (#24132 intentional gaps).
     *
     * @var list<string>
     */
    private const UNADVERTISED_CLASS_CONSTANTS = [
        'CURLOPT_WRITEDATA',
        'CURLM_UNKNOWN_OPTION',
    ];

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        $constants = [];
        $ref = new \ReflectionClass(self::class);
        foreach ($ref->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $c) {
            $value = $c->getValue();
            if (!\is_int($value)) {
                continue;
            }
            $name = $c->getName();
            if (\in_array($name, self::UNADVERTISED_CLASS_CONSTANTS, true)) {
                continue;
            }
            if (\in_array($name, self::PHP84_OPTION_CONSTANT_NAMES, true)
                && !CurlExtensionPolicy::advertisesPhp84OptionConstants()) {
                continue;
            }
            $constants[$name] = $value;
        }
        // curl_version_info age selector (php-src curl.stub.php / curlver.h; #24099, #24463).
        $constants['CURLVERSION_NOW'] = VmCurlCore::CURLVERSION_NOW;

        return $constants;
    }
}
