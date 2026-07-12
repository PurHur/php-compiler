--TEST--
Stdlib: get_defined_functions() lists extension builtins (#17415, basic_functions.c)
--FILE--
<?php
$f = get_defined_functions();
echo (function_exists('ctype_alnum') ? 'T' : 'F'), ' ';
echo (in_array('ctype_alnum', $f['internal'], true) ? 'T' : 'F'), "\n";
echo (function_exists('filter_var') ? 'T' : 'F'), ' ';
echo (in_array('filter_var', $f['internal'], true) ? 'T' : 'F'), "\n";
--EXPECT--
T T
T T
