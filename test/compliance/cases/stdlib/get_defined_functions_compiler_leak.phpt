--TEST--
stdlib get_defined_functions() hides __compiler_* helpers (#15046, ext/standard/basic_functions.c)
--FILE--
<?php
$internal = get_defined_functions()['internal'];
echo $internal[0] === 'zend_version' ? '1' : '0';
echo function_exists('__compiler_is_superglobal_name') ? '1' : '0';
$leaked = false;
foreach ($internal as $name) {
    if (str_starts_with($name, '__compiler_')) {
        $leaked = true;
        break;
    }
}
echo $leaked ? '1' : '0';
echo "\n";
--EXPECT--
100
