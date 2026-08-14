--TEST--
ArrayObject::asort()/ksort() reject non-int $flags with TypeError (#31035, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject([3, 1, 2]);
foreach (['asort', 'ksort'] as $m) {
    try {
        $ao->$m('x');
        echo "$m COERCED\n";
    } catch (TypeError $e) {
        echo $m, ' ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
asort ArrayObject::asort(): Argument #1 ($flags) must be of type int, string given
ksort ArrayObject::ksort(): Argument #1 ($flags) must be of type int, string given
