<?php
declare(strict_types=1);

/**
 * Repro for #19448 — iconv_mime_decode_headers() must match Zend.
 */
$headers = "From: =?UTF-8?B?SGVsbG8=?= <a@b.c>\r\nSubject: =?UTF-8?Q?Test?= world\r\n\r\n";
if (!function_exists('iconv_mime_decode_headers')) {
    echo "missing\n";
    exit(1);
}
$decoded = iconv_mime_decode_headers($headers);
if (!is_array($decoded)
    || ($decoded['From'] ?? null) !== 'Hello <a@b.c>'
    || ($decoded['Subject'] ?? null) !== 'Test world'
) {
    echo "fail\n";
    var_export($decoded);
    echo "\n";
    exit(1);
}
echo "ok\n";
