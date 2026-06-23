<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: settype($obj, 'int') on plain object — E_WARNING + 1 (#10690, ext/standard/type.c).
 */

$o = new stdClass();
$ok = @settype($o, 'int');
echo $ok ? "true\n" : "false\n";
var_export($o);
echo "\n";
