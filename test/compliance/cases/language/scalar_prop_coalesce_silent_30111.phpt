--TEST--
Language: $scalar->prop ?? $d is silent — Zend BP_VAR_IS (#30111)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr) {
    echo "WARNING: $errstr\n";
});

foreach ([false, true, null, 0, 3.14, "", "hello"] as $v) {
    echo var_export($v->x ?? "d", true), "\n";
}
--EXPECT--
'd'
'd'
'd'
'd'
'd'
'd'
'd'
