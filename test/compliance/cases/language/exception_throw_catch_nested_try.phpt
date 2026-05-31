--TEST--
Language: catch variable survives nested try in catch body (issue #195)
--FILE--
<?php
try {
    throw new Exception('x');
} catch (Exception $e) {
    try {
        echo "inner\n";
    } catch (Exception $ignored) {
    }
    echo $e->getMessage(), "\n";
}
--EXPECT--
inner
x
