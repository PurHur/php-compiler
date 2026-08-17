<?php

declare(strict_types=1);

/**
 * Issue #27951 — strrchr Reflection return string|false (ext/standard/string.stub.php).
 *
 * php-src: ext/standard/string.stub.php — function strrchr(string $haystack, string $needle): string|false
 */

$r = new ReflectionFunction('strrchr');
echo 'ret:', (string) $r->getReturnType(), "\n";
var_export(strrchr('abc', 'z'));
echo "\n";
var_export(strrchr('abc-def', '-'));
echo "\n";
