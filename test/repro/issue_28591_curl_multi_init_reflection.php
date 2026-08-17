<?php
/**
 * Repro #28591 — curl_multi_init() Reflection return must be CurlMultiHandle
 * (php-src ext/curl/curl.stub.php), not legacy resource. Runtime type stays CurlMultiHandle.
 *
 *   PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_ENABLE_CURL=1 php bin/vm.php test/repro/issue_28591_curl_multi_init_reflection.php
 */
$r = new ReflectionFunction('curl_multi_init');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
$mh = curl_multi_init();
echo 'type=', get_debug_type($mh), PHP_EOL;
curl_multi_close($mh);
