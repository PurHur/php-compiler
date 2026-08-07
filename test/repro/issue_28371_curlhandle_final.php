<?php

declare(strict_types=1);

/**
 * Repro #28371 — CurlHandle / CurlMultiHandle / CurlShareHandle must be final
 * (php-src ext/curl/curl.stub.php).
 *
 * Run:
 *   PHP_COMPILER_ENABLE_CURL=1 PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28371_curlhandle_final.php
 *
 * Extend rejection is covered by compliance
 * `curl_handle_classes_extend_final.phpt` / `curl_multi_handle_extend_final.phpt` /
 * `curl_share_handle_extend_final.phpt` (fatal, exit 255).
 */
foreach (['CurlHandle', 'CurlMultiHandle', 'CurlShareHandle'] as $c) {
    $r = new ReflectionClass($c);
    echo "$c isFinal=", var_export($r->isFinal(), true), "\n";
}
