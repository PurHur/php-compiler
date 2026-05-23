<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: preg_quote() on value-boxed string paths.
 */

$pattern = 'lib/JIT.php';
$quoted = preg_quote($pattern, '/');
echo is_string($quoted) ? '1' : '0';
echo "\n";
echo false !== strpos($quoted, 'JIT') ? '1' : '0';
echo "\n";
