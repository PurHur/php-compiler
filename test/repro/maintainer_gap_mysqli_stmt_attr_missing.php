<?php

declare(strict_types=1);

/** Repro #22175 — mysqli_stmt_attr_get / attr_set registration. */
echo 'mysqli_stmt_execute=', function_exists('mysqli_stmt_execute') ? 'yes' : 'NO', "\n";
echo 'mysqli_stmt_attr_get=', function_exists('mysqli_stmt_attr_get') ? 'yes' : 'NO', "\n";
echo 'mysqli_stmt_attr_set=', function_exists('mysqli_stmt_attr_set') ? 'yes' : 'NO', "\n";
echo 'MYSQLI_STMT_ATTR_CURSOR_TYPE=', defined('MYSQLI_STMT_ATTR_CURSOR_TYPE') ? (string) MYSQLI_STMT_ATTR_CURSOR_TYPE : 'NO', "\n";
echo 'MYSQLI_STMT_ATTR_PREFETCH_ROWS=', defined('MYSQLI_STMT_ATTR_PREFETCH_ROWS') ? (string) MYSQLI_STMT_ATTR_PREFETCH_ROWS : 'NO', "\n";
echo 'class_mysqli_stmt=', class_exists('mysqli_stmt') ? 'yes' : 'NO', "\n";
if (class_exists('mysqli_stmt')) {
    echo 'method_attr_get=', method_exists('mysqli_stmt', 'attr_get') ? 'yes' : 'NO', "\n";
    echo 'method_attr_set=', method_exists('mysqli_stmt', 'attr_set') ? 'yes' : 'NO', "\n";
}
try {
    mysqli_stmt_attr_get();
    echo "arity_get=NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'arity_get=ArgumentCountError', "\n";
}
try {
    mysqli_stmt_attr_set();
    echo "arity_set=NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'arity_set=ArgumentCountError', "\n";
}
try {
    mysqli_stmt_attr_get(null, 0);
    echo "type_get=NO_THROW\n";
} catch (TypeError $e) {
    echo 'type_get=TypeError', "\n";
}
