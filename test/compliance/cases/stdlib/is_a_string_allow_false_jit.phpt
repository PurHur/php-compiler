--TEST--
stdlib is_a() JIT — string subject with allow_string=false returns false (#4853)
--FILE--
<?php
var_export(is_a('stdClass', 'stdClass'));
echo "\n";
--EXPECT--
false
