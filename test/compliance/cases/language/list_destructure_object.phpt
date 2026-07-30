--TEST--
Language: list()/[] destructuring of plain object raises Error (#25096, Zend/zend_vm_def.h FETCH_LIST)
--FILE--
<?php
try {
    $o = (object) [0 => 1, 1 => 2];
    [$a, $b] = $o;
    echo "ok:$a$b\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $o = (object) [0 => 10, 1 => 20];
    list($a, $b) = $o;
    echo "ok:$a$b\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Error:Cannot use object of type stdClass as array
Error:Cannot use object of type stdClass as array
