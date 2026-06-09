--TEST--
stdlib is_a() — string subject with allow_string=false returns false (#4853, ext/standard/class.c)
--FILE--
<?php
var_export(is_a('stdClass', 'stdClass'));
echo "\n";
var_export(is_a('stdClass', 'stdClass', true));
echo "\n";
var_export(is_a(new stdClass(), 'stdClass'));
echo "\n";
--EXPECT--
false
true
true
