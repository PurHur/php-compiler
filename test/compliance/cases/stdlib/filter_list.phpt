--TEST--
Stdlib: filter_list() — VM-supported filter names (#3485, ext/filter/filter.c)
--FILE--
<?php
$list = filter_list();
foreach (['int', 'boolean', 'float', 'validate_regexp', 'validate_email'] as $name) {
    echo in_array($name, $list, true) ? '1' : '0';
}
echo "\n";
echo count($list), "\n";
--EXPECT--
11111
21
