<?php
// #18933 — http_response_code(null) TypeError (ext/standard/head.c).
try {
    http_response_code(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
