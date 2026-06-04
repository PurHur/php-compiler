--TEST--
Language: string offset used as array throws Zend Error (#5399)
--FILE--
<?php
$s = 'ab';
try {
    $s[0][0] = 'x';
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot use string offset as an array
