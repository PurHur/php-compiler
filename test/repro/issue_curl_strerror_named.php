<?php
/**
 * Repro #32352 — curl_strerror() named $error_code (ext/curl/interface.c).
 */
echo curl_strerror(error_code: 0), "\n";
echo curl_multi_strerror(error_code: 0), "\n";
