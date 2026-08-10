<?php

/**
 * #29664 — $arr[$float] ?? / ??= emit Implicit conversion Deprecated once when key is set
 * (Zend parity; sibling #29560 empty/isset).
 *
 * php-src: Zend/zend_execute.c float offset cast / coalesce dim.
 */
error_reporting(E_ALL);

function capture(int $errno, string $message): bool
{
    echo ($errno === E_DEPRECATED ? 'D:' : 'W:'), $message, "\n";

    return true;
}
set_error_handler('capture');

echo "?? present:\n";
$a = [1, 2, 3];
echo ($a[1.5] ?? 'x'), "\n";

echo "??= present:\n";
$b = [1, 2, 3];
$b[1.5] ??= 9;
echo $b[1], "\n";

echo "??= missing:\n";
$c = [];
$c[1.5] ??= 9;
var_export($c);
echo "\n";

echo "?? missing:\n";
$d = [];
echo ($d[1.5] ?? 'x'), "\n";

echo "empty/isset:\n";
$e = [1 => 'x'];
var_export(isset($e[1.5]));
echo "\n";
var_export(empty($e[1.5]));
echo "\n";
