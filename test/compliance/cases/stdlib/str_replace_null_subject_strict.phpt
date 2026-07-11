--TEST--
stdlib str_replace()/preg_replace() null subject TypeError under strict_types (#11938)
--FILE--
<?php
declare(strict_types=1);
try {
    str_replace('a', 'b', null);
    echo "str_replace: no throw\n";
} catch (TypeError $e) {
    echo "str_replace: TypeError\n";
}
try {
    preg_replace('/a/', 'b', null);
    echo "preg_replace: no throw\n";
} catch (TypeError $e) {
    echo "preg_replace: TypeError\n";
}
?>
--EXPECT--
str_replace: TypeError
preg_replace: TypeError
