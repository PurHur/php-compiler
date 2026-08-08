--TEST--
function/class/interface/trait/enum_exists argc → ArgumentCountError (#28475)
--FILE--
<?php
foreach (['function_exists', 'class_exists', 'interface_exists', 'trait_exists', 'enum_exists'] as $fn) {
    try {
        $fn();
        echo "$fn:ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    function_exists('strlen', 'x');
    echo "function_exists/2:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    class_exists('stdClass', true, 'x');
    echo "class_exists/3:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError:function_exists() expects exactly 1 argument, 0 given
ArgumentCountError:class_exists() expects at least 1 argument, 0 given
ArgumentCountError:interface_exists() expects at least 1 argument, 0 given
ArgumentCountError:trait_exists() expects at least 1 argument, 0 given
ArgumentCountError:enum_exists() expects at least 1 argument, 0 given
ArgumentCountError:function_exists() expects exactly 1 argument, 2 given
ArgumentCountError:class_exists() expects at most 2 arguments, 3 given
