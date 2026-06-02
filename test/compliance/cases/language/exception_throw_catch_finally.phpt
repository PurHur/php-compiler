--TEST--
Language: catch variable with finally — catch then finally (Zend zend_exceptions.c, #195)
--FILE--
<?php
try {
    throw new Exception('x');
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
} finally {
    echo "f\n";
}
--EXPECT--
x
f
