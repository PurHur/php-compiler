<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: session_name() after body output (#9376, ext/session/session.c).
 */

echo 'body';
var_export(session_name('custom'));
echo "\n";
var_export(session_name());
echo "\n";
