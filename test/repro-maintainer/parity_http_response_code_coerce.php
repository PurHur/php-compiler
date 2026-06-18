<?php
// php-src-strict repro for #4454 — http_response_code() numeric-string coercion.
http_response_code("404");
echo http_response_code(), "\n";
http_response_code(null);
echo http_response_code() === 404 ? "ok\n" : "fail\n";
try {
    http_response_code([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
