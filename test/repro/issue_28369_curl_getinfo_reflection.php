<?php
/**
 * Repro #28369 — curl_getinfo() Reflection must match php-src curl.stub.php:
 *   curl_getinfo(CurlHandle $handle, ?int $option = null): mixed
 *
 *   PHP_COMPILER_ENABLE_CURL=1 PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28369_curl_getinfo_reflection.php
 */
$r = new ReflectionFunction('curl_getinfo');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName(),
        ' type=', $p->hasType() ? (string) $p->getType() : '(none)',
        $p->isOptional() ? ' opt' : ' req',
        $p->isDefaultValueAvailable() ? ' def='.var_export($p->getDefaultValue(), true) : '',
        PHP_EOL;
}
$ch = curl_init();
echo 'named_ok=', is_array(curl_getinfo(handle: $ch)) ? '1' : '0', PHP_EOL;
echo 'named_null_opt=', is_array(curl_getinfo(handle: $ch, option: null)) ? '1' : '0', PHP_EOL;
try {
    curl_getinfo(handle: 'x');
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
curl_close($ch);
