--TEST--
Language: AOT statements after try/catch/finally run (#32371, Zend ZEND_FAST_CALL)
--FILE--
<?php
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
--EXPECT--
SCFE11
