--TEST--
AOT is_a() null/scalar subject returns false (#10873)
--FILE--
<?php
echo is_a(null, 'stdClass') ? '1' : '0';
echo is_a(1, 'stdClass') ? '1' : '0';
echo is_a([], 'stdClass') ? '1' : '0';
var_export(is_a('stdClass', 'stdClass'));
--EXPECT--
000false
