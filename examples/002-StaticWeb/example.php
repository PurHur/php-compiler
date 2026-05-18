<?php

declare(strict_types=1);

/**
 * Minimal page compilable with AOT (no superglobals): header + echo.
 * htmlspecialchars() AOT lowering is still being fixed; use VM/JIT or pre-escaped literals for now.
 *
 * Lint: php bin/compile.php -l examples/002-StaticWeb/example.php
 * Build (requires LLVM/clang): php bin/compile.php -o staticweb examples/002-StaticWeb/example.php
 */
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><body>';
echo '<h1>Hello World</h1>', "\n";
echo '</body></html>';
