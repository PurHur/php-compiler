<?php
/**
 * Repro #32340 — curl_share_strerror() NestedJIT (php-src ext/curl/share.c).
 *
 * Needs curl advertised (host ext/curl or PHP_COMPILER_ENABLE_CURL=1).
 */
echo curl_share_strerror(0), "\n";
echo curl_share_strerror(1), "\n";
echo curl_share_strerror(5), "\n";
echo curl_share_strerror(999), "\n";
