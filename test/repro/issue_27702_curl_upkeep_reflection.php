<?php
/**
 * Repro #27702 — curl_upkeep() Reflection must match php-src curl.stub.php:
 *   curl_upkeep(CurlHandle $handle): bool
 * Named `$handle` is accepted; a non-CurlHandle TypeErrors.
 *
 *   PHP_COMPILER_ENABLE_CURL=1 php bin/vm.php test/repro/issue_27702_curl_upkeep_reflection.php
 */
$r = new ReflectionFunction('curl_upkeep');
echo 'arity=', $r->getNumberOfParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', ($p->getType() ? (string) $p->getType() : '?'), PHP_EOL;
}
echo 'ret=', $r->getReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
try {
    curl_upkeep(handle: 'x');
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
$ch = curl_init();
echo 'named_ok=', curl_upkeep(handle: $ch) ? '1' : '0', PHP_EOL;
curl_close($ch);
