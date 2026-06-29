--TEST--
Stdlib: filter_list() JIT — VM-supported filter names (#3485)
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
