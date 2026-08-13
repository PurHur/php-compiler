<?php

declare(strict_types=1);

/**
 * Repro #30792 — is_resource() false after fclose; gettype still "resource (closed)".
 *
 * php-src: ext/standard/type.c / Zend closed-resource typing
 */
$f = fopen('php://memory', 'r');
echo 'open=', var_export(is_resource($f), true), "\n";
fclose($f);
echo 'closed=', var_export(is_resource($f), true), "\n";
echo gettype($f), "\n";
