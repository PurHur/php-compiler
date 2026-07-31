--TEST--
ext/mysqli mysqli_execute_query registration (#21895)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
echo 'mysqli_execute_query:', function_exists('mysqli_execute_query') ? 'yes' : 'no', "\n";
$rc = new ReflectionClass('mysqli');
echo 'mysqli::execute_query:', $rc->hasMethod('execute_query') ? 'yes' : 'no', "\n";
try {
    mysqli_execute_query(1, 'SELECT 1');
    echo "TypeError:no\n";
} catch (TypeError $e) {
    echo 'TypeError:', str_contains($e->getMessage(), 'must be of type mysqli') ? 'yes' : $e->getMessage(), "\n";
}
?>
--EXPECT--
mysqli_execute_query:yes
mysqli::execute_query:yes
TypeError:yes
