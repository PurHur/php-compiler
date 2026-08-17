<?php
// zend.assertions=-1 must compile assert() out: no throw AND no side effects (re-#31195).
error_reporting(E_ALL);
echo 'zend.assertions=', var_export(ini_get('zend.assertions'), true), "\n";
$ran = false;
try {
    assert(($ran = true) && false, 'nope');
    echo 'SURVIVED ran=', $ran ? '1' : '0', "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), ' ran=', $ran ? '1' : '0', "\n";
}
