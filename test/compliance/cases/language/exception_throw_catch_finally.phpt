--TEST--
Language: catch variable with finally — finally runs before catch body (issue #195)
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
f
x
