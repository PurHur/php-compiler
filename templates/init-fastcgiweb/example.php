<?php

/**
 * Minimal FastCGI / deploy presenter (issue #2331).
 *
 * Routes (PATH_INFO after front controller):
 *   empty PATH_INFO  → health plain-text "ok"
 *   any other path   → echo REQUEST_URI and SCRIPT_NAME (CGI diagnostics)
 *
 * VM:
 *   ./phpc run examples/009-FastCGIWeb/example.php
 *
 * Serve (loopback harness until FastCGI adapter #173):
 *   ./phpc serve 127.0.0.1:8080 examples/009-FastCGIWeb
 *   curl -s http://127.0.0.1:8080/example.php
 *   curl -s http://127.0.0.1:8080/example.php/ping
 *
 * Deploy + nginx/FastCGI: docs/deploy-web-aot.md (#445); integration target #173.
 */
$pathInfo = $_SERVER['PATH_INFO'] ?? '';

header('Content-Type: text/plain; charset=UTF-8');

// strlen guard: VM mis-lowers chained !== on coalesced $_SERVER fetches (#2351 smoke).
if (strlen($pathInfo) > 1) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/example.php';
    echo 'REQUEST_URI=', $requestUri, "\n";
    echo 'SCRIPT_NAME=', $scriptName, "\n";
    echo 'PATH_INFO=', $pathInfo, "\n";
} else {
    echo "ok\n";
}
