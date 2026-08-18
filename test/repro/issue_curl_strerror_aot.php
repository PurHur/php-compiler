<?php
/**
 * Repro #32352 — curl_strerror() / curl_multi_strerror() NestedJIT
 * (php-src ext/curl/interface.c).
 *
 * Needs curl advertised (host ext/curl or PHP_COMPILER_ENABLE_CURL=1).
 */
echo curl_strerror(0), "\n";
echo curl_strerror(6), "\n";
echo curl_strerror(9999), "\n";
echo curl_multi_strerror(0), "\n";
echo curl_multi_strerror(5), "\n";
echo curl_multi_strerror(99), "\n";
