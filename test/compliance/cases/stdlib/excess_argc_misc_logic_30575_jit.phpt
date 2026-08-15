--TEST--
preg_split/spl_autoload_register/iterator_to_array/count/get_mangled excess argc JIT → ArgumentCountError (#30575)
--JIT--
--FILE--
<?php
$cases = [
    'preg_split("/,/", "a,b", -1, 0, "x")',
    'spl_autoload_register(fn() => null, true, true, "x")',
    'iterator_to_array(new ArrayIterator([1]), true, "x")',
    'iterator_count(new ArrayIterator([1]), "x")',
    'get_mangled_object_vars(new stdClass, "x")',
];
foreach ($cases as $code) {
    try {
        eval($code . ';');
        echo "NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
preg_split() expects at most 4 arguments, 5 given
spl_autoload_register() expects at most 3 arguments, 4 given
iterator_to_array() expects at most 2 arguments, 3 given
iterator_count() expects exactly 1 argument, 2 given
get_mangled_object_vars() expects exactly 1 argument, 2 given
