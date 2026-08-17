<?php
/**
 * Repro #27711 — curl_share_init_persistent() Reflection arity/params/return
 * (php-src ext/curl/curl.stub.php). Named share_options: must work.
 *
 *   PHP_COMPILER_PROFILE=8.5 PHP_COMPILER_ENABLE_CURL=1 php bin/vm.php test/repro/issue_27711_curl_share_init_persistent_reflection.php
 */
$r = new ReflectionFunction('curl_share_init_persistent');
echo 'arity=', $r->getNumberOfParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo 'param=', $p->getName(), ':', ($p->hasType() ? (string) $p->getType() : '?'), PHP_EOL;
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
try {
    $h = curl_share_init_persistent(share_options: [CURL_LOCK_DATA_DNS]);
    echo 'named_ok=', get_debug_type($h), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
