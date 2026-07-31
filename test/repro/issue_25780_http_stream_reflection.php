<?php
/**
 * Issue #25780 — get_headers / http_response_code / stream_socket_pair / headers_sent /
 * flush / ob_* / getmxrr Reflection stubs vs php-src.
 */
foreach (['get_headers', 'http_response_code', 'stream_socket_pair', 'headers_sent', 'flush', 'ob_get_status', 'ob_list_handlers', 'getmxrr'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '-';
        $d = $p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : ($p->isOptional() ? 'OPT' : 'REQ');
        $ps[] = ($p->isPassedByReference() ? '&' : '').$p->getName().':'.$t.'='.$d;
    }
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : '-';
    echo $fn, ' => (', $ret, ') ', implode(', ', $ps), "\n";
}

// Named associative: must resolve (was format-only in InternalArgInfo).
try {
    $named = @get_headers(url: 'http://127.0.0.1/', associative: true);
    echo 'named associative ok=', var_export(is_array($named) || false === $named, true), "\n";
} catch (Throwable $e) {
    echo 'named associative: ', $e->getMessage(), "\n";
}
