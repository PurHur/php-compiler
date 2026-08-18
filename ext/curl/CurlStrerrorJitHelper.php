<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * curl_strerror() / curl_multi_strerror() for compiled JIT/AOT (#32352, php-in-PHP).
 *
 * NestedJIT-safe: CURLE_* / CURLM_* strings as class consts (posix_strerror #12477 /
 * curl_share_strerror #32340 shape) — no libcurl FFI in the AOT binary.
 *
 * VM curl_strerror still uses live {@see VmCurlNative::easyStrerror()} (#25813).
 * php-src: ext/curl/interface.c — PHP_FUNCTION(curl_strerror) / curl_multi_strerror
 */
final class CurlStrerrorJitHelper
{
    /**
     * libcurl curl_easy_strerror() (Ubuntu 22.04 / 7.81; unused CURLE_* → "Unknown error").
     *
     * @var array<int, string>
     */
    public const EASY_ERRORS = [
        0 => 'No error',
        1 => 'Unsupported protocol',
        2 => 'Failed initialization',
        3 => 'URL using bad/illegal format or missing URL',
        4 => 'A requested feature, protocol or option was not found built-in in this libcurl due to a build-time decision.',
        5 => 'Couldn\'t resolve proxy name',
        6 => 'Couldn\'t resolve host name',
        7 => 'Couldn\'t connect to server',
        8 => 'Weird server reply',
        9 => 'Access denied to remote resource',
        10 => 'FTP: The server failed to connect to data port',
        11 => 'FTP: unknown PASS reply',
        12 => 'FTP: Accepting server connect has timed out',
        13 => 'FTP: unknown PASV reply',
        14 => 'FTP: unknown 227 response format',
        15 => 'FTP: can\'t figure out the host in the PASV response',
        16 => 'Error in the HTTP2 framing layer',
        17 => 'FTP: couldn\'t set file type',
        18 => 'Transferred a partial file',
        19 => 'FTP: couldn\'t retrieve (RETR failed) the specified file',
        21 => 'Quote command returned error',
        22 => 'HTTP response code said error',
        23 => 'Failed writing received data to disk/application',
        25 => 'Upload failed (at start/before it took off)',
        26 => 'Failed to open/read local data from file/application',
        27 => 'Out of memory',
        28 => 'Timeout was reached',
        30 => 'FTP: command PORT failed',
        31 => 'FTP: command REST failed',
        33 => 'Requested range was not delivered by the server',
        34 => 'Internal problem setting up the POST',
        35 => 'SSL connect error',
        36 => 'Couldn\'t resume download',
        37 => 'Couldn\'t read a file:// file',
        38 => 'LDAP: cannot bind',
        39 => 'LDAP: search failed',
        41 => 'A required function in the library was not found',
        42 => 'Operation was aborted by an application callback',
        43 => 'A libcurl function was given a bad argument',
        45 => 'Failed binding local connection end',
        47 => 'Number of redirects hit maximum amount',
        48 => 'An unknown option was passed in to libcurl',
        49 => 'Malformed option provided in a setopt',
        52 => 'Server returned nothing (no headers, no data)',
        53 => 'SSL crypto engine not found',
        54 => 'Can not set SSL crypto engine as default',
        55 => 'Failed sending data to the peer',
        56 => 'Failure when receiving data from the peer',
        58 => 'Problem with the local SSL certificate',
        59 => 'Couldn\'t use specified SSL cipher',
        60 => 'SSL peer certificate or SSH remote key was not OK',
        61 => 'Unrecognized or bad HTTP Content or Transfer-Encoding',
        62 => 'Invalid LDAP URL',
        63 => 'Maximum file size exceeded',
        64 => 'Requested SSL level failed',
        65 => 'Send failed since rewinding of the data stream failed',
        66 => 'Failed to initialise SSL crypto engine',
        67 => 'Login denied',
        68 => 'TFTP: File Not Found',
        69 => 'TFTP: Access Violation',
        70 => 'Disk full or allocation exceeded',
        71 => 'TFTP: Illegal operation',
        72 => 'TFTP: Unknown transfer ID',
        73 => 'Remote file already exists',
        74 => 'TFTP: No such user',
        75 => 'Conversion failed',
        76 => 'Caller must register CURLOPT_CONV_ callback options',
        77 => 'Problem with the SSL CA cert (path? access rights?)',
        78 => 'Remote file not found',
        79 => 'Error in the SSH layer',
        80 => 'Failed to shut down the SSL connection',
        81 => 'Socket not ready for send/recv',
        82 => 'Failed to load CRL file (path? access rights?, format?)',
        83 => 'Issuer check against peer certificate failed',
        84 => 'FTP: The server did not accept the PRET command.',
        85 => 'RTSP CSeq mismatch or invalid CSeq',
        86 => 'RTSP session error',
        87 => 'Unable to parse FTP file list',
        88 => 'Chunk callback failed',
        89 => 'The max connection limit is reached',
        90 => 'SSL public key does not match pinned public key',
        91 => 'SSL server certificate status verification FAILED',
        92 => 'Stream error in the HTTP/2 framing layer',
        93 => 'API function called from within callback',
        94 => 'An authentication function returned an error',
        95 => 'HTTP/3 error',
        96 => 'QUIC connection error',
        97 => 'proxy handshake error',
        98 => 'SSL Client Certificate required',
    ];

    /**
     * libcurl curl_multi_strerror() (Ubuntu 22.04 / 7.81).
     *
     * @var array<int, string>
     */
    public const MULTI_ERRORS = [
        0 => 'No error',
        1 => 'Invalid multi handle',
        2 => 'Invalid easy handle',
        3 => 'Out of memory',
        4 => 'Internal error',
        5 => 'Invalid socket argument',
        6 => 'Unknown option',
        7 => 'The easy handle is already added to a multi handle',
        8 => 'API function called from within callback',
        9 => 'Wakeup is unavailable or failed',
        10 => 'A libcurl function was given a bad argument',
        11 => 'Operation was aborted by an application callback',
    ];

    public static function easy(int $code): string
    {
        return self::EASY_ERRORS[$code] ?? 'Unknown error';
    }

    public static function multi(int $code): string
    {
        return self::MULTI_ERRORS[$code] ?? 'Unknown error';
    }
}
