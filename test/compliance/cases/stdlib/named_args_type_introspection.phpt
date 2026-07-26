--TEST--
get_debug_type/count/is_* named value argument (VM, issue #23263)
--FILE--
<?php
echo get_debug_type(value: 1), PHP_EOL;
echo count(value: [1, 2]), PHP_EOL;
var_export(is_string(value: 'a'));
echo PHP_EOL;
var_export(is_array(value: [1]));
echo PHP_EOL;
var_export(is_finite(num: 1.5));
echo PHP_EOL;
foreach (['get_debug_type', 'count', 'sizeof', 'is_string', 'is_array', 'is_countable', 'is_iterable', 'is_finite'] as $f) {
    $rf = new ReflectionFunction($f);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ':', implode(',', $names), PHP_EOL;
}
try {
    count(var: [1]);
    echo "var accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
int
2
true
true
true
get_debug_type:value
count:value,mode
sizeof:value,mode
is_string:value
is_array:value
is_countable:value
is_iterable:value
is_finite:num
Unknown named parameter $var
