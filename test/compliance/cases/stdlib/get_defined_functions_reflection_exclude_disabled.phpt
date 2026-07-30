--TEST--
Stdlib: get_defined_functions() Reflection exclude_disabled optional default true (#25277, basic_functions.stub.php)
--FILE--
<?php
$p = (new ReflectionFunction('get_defined_functions'))->getParameters()[0];
echo $p->getName(), ' ',
    ($p->isOptional() ? 'opt' : 'req'), ' ',
    ($p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'N/A'), "\n";
$named = get_defined_functions(exclude_disabled: false);
echo is_array($named) ? "named-ok\n" : "named-fail\n";
--EXPECT--
exclude_disabled opt true
named-ok
