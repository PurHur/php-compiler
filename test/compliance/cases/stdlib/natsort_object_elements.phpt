--TEST--
stdlib natsort/natcasesort object elements throw Error (#12244, ext/standard/array.c)
--FILE--
<?php
$a = [new stdClass(), new stdClass()];
try {
    natsort($a);
    echo "natsort_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    natcasesort($a);
    echo "natcasesort_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Object of class stdClass could not be converted to string
Object of class stdClass could not be converted to string
