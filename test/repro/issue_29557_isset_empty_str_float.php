<?php

declare(strict_types=1);

/**
 * #29557 — isset()/empty() on string + float dim: Zend Implicit-conversion Deprecated
 * (once per op), not "String offset cast occurred". Direct read keeps string-offset cast.
 */
error_reporting(E_ALL);

$messages = [];
set_error_handler(static function (int $no, string $msg) use (&$messages): bool {
    $messages[] = [$no, $msg];

    return true;
});

$s = 'ab';
$isset = isset($s[1.5]);
$empty = empty($s[1.5]);
$read = $s[1.5];

restore_error_handler();

foreach ($messages as [$no, $msg]) {
    echo ($no === E_DEPRECATED ? 'D:' : ($no === E_WARNING ? 'W:' : "E{$no}:")), $msg, "\n";
}
echo 'isset=', var_export($isset, true), "\n";
echo 'empty=', var_export($empty, true), "\n";
echo 'read=', var_export($read, true), "\n";
