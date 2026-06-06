--TEST--
Language: readonly() marks dynamic objects immutable (PHP 8.4, #6485)
--FILE--
<?php
var_export(function_exists('readonly'));
echo "\n";
$o = (object)['x' => 1];
readonly($o);
try {
    $o->x = 2;
    echo "mutated\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    readonly($o);
} catch (Error $e) {
    echo 'reapply: ', $e->getMessage(), "\n";
}
try {
    readonly(1);
} catch (TypeError $e) {
    echo 'type: ', $e->getMessage(), "\n";
}
--EXPECT--
true
Cannot modify readonly object of class stdClass
reapply: Object is already readonly
type: readonly(): Argument #1 ($object) must be of type object, int given
