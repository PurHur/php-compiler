--TEST--
iconv ICONV_IMPL / ICONV_VERSION identity constants (#24053)
--FILE--
<?php
declare(strict_types=1);

// Bare names (not constant()) so AOT matches VM.
// Backend is CharsetEngine (php-compiler), not glibc — honesty over Zend string match.
echo defined('ICONV_IMPL') && is_string(ICONV_IMPL) && ICONV_IMPL === 'php-compiler'
    ? "ICONV_IMPL=ok\n" : "ICONV_IMPL=bad\n";
echo defined('ICONV_VERSION') && is_string(ICONV_VERSION) && ICONV_VERSION === '1.0'
    ? "ICONV_VERSION=ok\n" : "ICONV_VERSION=bad\n";
echo defined('ICONV_MIME_DECODE_STRICT') && ICONV_MIME_DECODE_STRICT === 1
    ? "ICONV_MIME_DECODE_STRICT=ok\n" : "ICONV_MIME_DECODE_STRICT=bad\n";
?>
--EXPECT--
ICONV_IMPL=ok
ICONV_VERSION=ok
ICONV_MIME_DECODE_STRICT=ok
