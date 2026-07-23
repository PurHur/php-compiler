<?php
/** Repro #22225 — mysqli_error_list / mysqli_stmt_error_list. */
foreach (['mysqli_error_list', 'mysqli_stmt_error_list'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
$m = mysqli_init();
$list = mysqli_error_list($m);
echo 'empty=', is_array($list) && $list === [] ? '1' : '0', "\n";
