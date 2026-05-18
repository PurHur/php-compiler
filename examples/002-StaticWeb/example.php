<?php

declare(strict_types=1);

/**
 * Minimal page compilable with AOT (no superglobals): header + htmlspecialchars + echo.
 * Lint: php bin/compile.php -l examples/002-StaticWeb/example.php
 * Build (requires LLVM/clang): php bin/compile.php -o staticweb examples/002-StaticWeb/example.php
 */
$name = 'World';
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><body>';
echo '<h1>Hello ', htmlspecialchars($name), "</h1>\n";
echo '</body></html>';
