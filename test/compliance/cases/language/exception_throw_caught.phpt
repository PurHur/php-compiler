--TEST--
Language: throw new Exception caught with getMessage (issue #195)
--FILE--
<?php
try {
    throw new Exception('x');
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
    echo $e->getCode(), "\n";
}
--EXPECT--
x
0
