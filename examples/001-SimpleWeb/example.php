<?php

declare(strict_types=1);

/**
 * Minimal web-style page: reads name from $_REQUEST (GET query or POST form body).
 *
 * VM:
 *   php bin/vm.php -q 'name=World' examples/001-SimpleWeb/example.php
 *   php bin/vm.php -p 'name=PostDev' examples/001-SimpleWeb/example.php
 *
 * Serve (from repo root):
 *   ./phpc serve 127.0.0.1:8080 examples/001-SimpleWeb
 *   curl -s 'http://127.0.0.1:8080/example.php?name=Dev'
 *   curl -s -X POST -d 'name=PostDev' 'http://127.0.0.1:8080/example.php'
 *
 * AOT (LLVM):
 *   php bin/compile.php -o simpleweb examples/001-SimpleWeb/example.php
 *   QUERY_STRING='name=World' ./simpleweb
 *   REQUEST_METHOD=POST REQUEST_BODY='name=PostDev' ./simpleweb
 *
 * $_REQUEST merges $_GET then $_POST (POST wins on duplicate keys). See lib/Web/Superglobals.php
 * and SuperglobalRefreshRuntime LLVM for runtime refresh per request (#5330).
 */
$name = (string) $_REQUEST['name'];
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><link rel="stylesheet" href="/style.css"></head><body>';
echo '<h1>Hello ', htmlspecialchars($name), "</h1>\n";
echo '<form method="post"><label>Name <input name="name" value="', htmlspecialchars($name), '"></label> ';
echo '<button type="submit">Save</button></form>';
echo '</body></html>';
