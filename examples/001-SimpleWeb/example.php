<?php

declare(strict_types=1);

/**
 * Minimal web-style page: reads ?name= from $_GET or POST body and prints HTML.
 * Run with: QUERY_STRING='name=World' php bin/vm.php examples/001-SimpleWeb/example.php
 * Or: php bin/vm.php -q 'name=World' examples/001-SimpleWeb/example.php
 * Or: php bin/vm.php -p 'name=World' examples/001-SimpleWeb/example.php
 * AOT (LLVM): php bin/compile.php -o simpleweb examples/001-SimpleWeb/example.php
 *   QUERY_STRING='name=World' ./simpleweb
 * Or: phpc build -o .phpc/bin/app example.php && phpc serve --aot examples/001-SimpleWeb
 * $_GET is read from QUERY_STRING at runtime (see lib/AOT/runtime/superglobals_refresh.c).
 * Optional: pass -q at compile time to bake $_GET for static-only builds.
 * $_SERVER['REQUEST_METHOD'] and $_REQUEST are populated automatically (see lib/Web/Superglobals.php).
 */
// AOT: set QUERY_STRING (or use phpc serve --aot); compile-time -q is optional.
$name = $_REQUEST['name'];
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><link rel="stylesheet" href="/style.css"></head><body>';
echo '<h1>Hello ', htmlspecialchars($name), "</h1>\n";
echo '</body></html>';
