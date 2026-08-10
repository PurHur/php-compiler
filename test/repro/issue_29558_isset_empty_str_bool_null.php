<?php

declare(strict_types=1);

/**
 * #29558 — isset()/empty() on string + bool/null dim: Zend silent coerce.
 * Direct read keeps "String offset cast occurred".
 */
error_reporting(E_ALL);

$messages = [];
set_error_handler(static function (int $no, string $msg) use (&$messages): bool {
    $messages[] = [$no, $msg];

    return true;
});

$s = 'ab';
$b = true;
$n = null;
$results = [
    'isset_true' => isset($s[true]),
    'isset_false' => isset($s[false]),
    'isset_null' => isset($s[null]),
    'empty_true' => empty($s[true]),
    'isset_var_bool' => isset($s[$b]),
    'isset_var_null' => isset($s[$n]),
    'empty_var_bool' => empty($s[$b]),
];
$readLit = $s[true];
$readVar = $s[$b];

restore_error_handler();

foreach ($messages as [$no, $msg]) {
    echo ($no === E_WARNING ? 'W:' : "E{$no}:"), $msg, "\n";
}
foreach ($results as $k => $v) {
    echo $k, '=', var_export($v, true), "\n";
}
echo 'read_lit=', var_export($readLit, true), "\n";
echo 'read_var=', var_export($readVar, true), "\n";
