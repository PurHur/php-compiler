<?php
/**
 * Repro #32340 — curl_share_strerror() named $error_code (ext/curl/share.c).
 */
echo curl_share_strerror(error_code: 0), "\n";
