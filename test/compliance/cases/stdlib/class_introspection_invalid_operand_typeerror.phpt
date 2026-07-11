--TEST--
stdlib get_parent_class()/method_exists() invalid operand TypeError (#12689, ext/standard/class.c)
--FILE--
<?php
foreach ([
    ['get_parent_class', static fn () => get_parent_class(false)],
    ['get_parent_class', static fn () => get_parent_class(1)],
    ['method_exists', static fn () => method_exists(false, 'x')],
    ['method_exists', static fn () => method_exists(1, 'x')],
] as [$label, $call]) {
    try {
        $call();
        echo $label, ": uncaught\n";
    } catch (TypeError $e) {
        echo $label, ": TypeError\n";
    }
}
?>
--EXPECT--
get_parent_class: TypeError
get_parent_class: TypeError
method_exists: TypeError
method_exists: TypeError
