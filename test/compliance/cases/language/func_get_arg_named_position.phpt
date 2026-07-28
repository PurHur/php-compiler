--TEST--
func_get_arg Zend stub named position param (#24456)
--FILE--
<?php
function f($a) {
    echo func_get_arg(position: 0), "\n";
}
f('x');
$rf = new ReflectionFunction('func_get_arg');
echo $rf->getParameters()[0]->getName(), "\n";
function g($a) {
    try {
        echo func_get_arg(arg_num: 0), "\n";
    } catch (Throwable $e) {
        echo $e->getMessage(), "\n";
    }
}
g('y');
?>
--EXPECT--
x
position
Unknown named parameter $arg_num
