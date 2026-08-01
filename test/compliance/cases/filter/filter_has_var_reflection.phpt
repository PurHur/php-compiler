--TEST--
stdlib filter_has_var Reflection names input_type/var_name (#26234)
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionFunction('filter_has_var');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
var_export(filter_has_var(input_type: INPUT_GET, var_name: 'nosuch'));
echo "\n";
try {
    filter_has_var(type: INPUT_GET, variable_name: 'x');
    echo "legacy=UNEXPECTED\n";
} catch (Error $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
--EXPECT--
input_type,var_name
false
legacy:Unknown named parameter $type
