<?php
/**
 * Repro #27628 — curl_share_init() Reflection return must be CurlShareHandle
 * (php-src ext/curl/curl.stub.php). Runtime type stays CurlShareHandle.
 *
 *   PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_ENABLE_CURL=1 php bin/vm.php test/repro/issue_27628_curl_share_init_reflection.php
 */
$r = new ReflectionFunction('curl_share_init');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
echo 'argc=', $r->getNumberOfParameters(), PHP_EOL;
$sh = curl_share_init();
echo 'type=', get_debug_type($sh), PHP_EOL;
curl_share_close($sh);
