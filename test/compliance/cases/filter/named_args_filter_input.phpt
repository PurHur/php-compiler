--TEST--
filter_input named arguments (VM, issue #23383)
--FILE--
<?php
var_export(filter_input(type: INPUT_GET, var_name: 'x'));
echo PHP_EOL;
$rf = new ReflectionFunction('filter_input');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
--EXPECT--
NULL
type
var_name
filter
options
