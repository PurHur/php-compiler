<?php

declare(strict_types=1);

/**
 * Minimal web-style page: reads ?name= from $_GET or POST body and prints HTML.
 * Run with: QUERY_STRING='name=World' php bin/vm.php examples/001-SimpleWeb/example.php
 * Or: php bin/vm.php -q 'name=World' examples/001-SimpleWeb/example.php
 * Or: php bin/vm.php -p 'name=World' examples/001-SimpleWeb/example.php
 * AOT (LLVM): php bin/compile.php -q 'name=World' -o simpleweb examples/001-SimpleWeb/example.php
 * $_GET is baked in at compile time from -q / QUERY_STRING (see lib/JIT/SuperglobalInit.php).
 * $_SERVER['REQUEST_METHOD'] and $_REQUEST are populated automatically (see lib/Web/Superglobals.php).
 */
// For AOT, pass the query at compile time: -q 'name=World' or QUERY_STRING=name=World
// $_GET is populated from that string during compilation (see SuperglobalInit).
$name = $_GET['name'];
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><link rel="stylesheet" href="/style.css"></head><body>';
echo '<h1>Hello ', htmlspecialchars($name), "</h1>\n";
echo '</body></html>';
