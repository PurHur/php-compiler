<?php
// #32371 — statements after try/catch/finally must run under AOT (Zend ZEND_FAST_CALL).
echo "S";
$x = 0;
try {
    throw new Exception("e");
} catch (Exception $e) {
    echo "C";
    $x = 1;
} finally {
    echo "F";
    $x += 10;
}
echo "E", $x, "\n";
