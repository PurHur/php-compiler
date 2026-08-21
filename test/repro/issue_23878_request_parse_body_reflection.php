<?php
// Repro #23878 — request_parse_body Reflection + named options (http.stub.php)
if (!function_exists('request_parse_body')) {
    echo "request_parse_body MISSING\n";
    return;
}
$r = new ReflectionFunction('request_parse_body');
$parts = [];
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    $parts[] = ($t ? (string) $t : '?').' $'.$p->getName().($p->isOptional() ? '=' : '');
}
$rt = $r->getReturnType();
echo 'request_parse_body(', implode(', ', $parts), '):', $rt ? (string) $rt : '?', "\n";
echo 'argc=', $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters(), "\n";
try {
    request_parse_body(options: []);
    echo "named_ok\n";
} catch (RequestParseBodyException $e) {
    // Zend: binding succeeded; missing Content-Type throws RequestParseBodyException.
    echo 'named_bound=', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'named_ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
