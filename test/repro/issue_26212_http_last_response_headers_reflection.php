<?php
declare(strict_types=1);

/**
 * Reflection return types for PHP 8.4 http_* last-response-header APIs (#26212).
 * php-src: ext/standard/http.stub.php
 */
foreach (['http_get_last_response_headers', 'http_clear_last_response_headers'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->getReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
var_export(http_get_last_response_headers());
echo "\n";
http_clear_last_response_headers();
echo "cleared\n";
