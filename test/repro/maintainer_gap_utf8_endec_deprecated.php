<?php
/**
 * Maintainer gap: utf8_encode()/utf8_decode() E_DEPRECATED (#18104).
 *
 * Zend 8.2: error_get_last() after each call reports function deprecation.
 */
utf8_encode('café');
$e = error_get_last();
echo null === $e ? "encode: null\n" : 'encode: '.$e['message']."\n";

utf8_decode('caf%');
$e = error_get_last();
echo null === $e ? "decode: null\n" : 'decode: '.$e['message']."\n";
