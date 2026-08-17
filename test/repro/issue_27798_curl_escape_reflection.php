<?php
/**
 * Repro #27798 — curl_escape/curl_unescape Reflection must match php-src curl.stub.php:
 *   curl_escape(CurlHandle $handle, string $string): string|false
 *   curl_unescape(CurlHandle $handle, string $string): string|false
 *
 *   PHP_COMPILER_ENABLE_CURL=1 php bin/vm.php test/repro/issue_27798_curl_escape_reflection.php
 */
foreach (['curl_escape', 'curl_unescape'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : '?', PHP_EOL;
    }
}
$ch = curl_init();
echo 'escape=', curl_escape(handle: $ch, string: 'a b'), PHP_EOL;
echo 'unescape=', curl_unescape(handle: $ch, string: 'a%20b'), PHP_EOL;
try {
    curl_escape(handle: 'x', string: 'y');
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
curl_close($ch);
