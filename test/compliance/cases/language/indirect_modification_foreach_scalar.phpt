--TEST--
Language: foreach by-ref scalar append throws Zend Error (#6325)
--FILE--
<?php
$arr = [1, 2, 3];
try {
    foreach ($arr as &$v) {
        $v[] = 4;
    }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot use a scalar value as an array
