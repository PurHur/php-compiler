--TEST--
Language: loose == string↔string scientific notation (#3680, Zend zendi_smart_strcmp)
--FILE--
<?php
echo ('0e5' == '0') ? "bool(true)\n" : "bool(false)\n";
echo ('0e5' == 0) ? "bool(true)\n" : "bool(false)\n";
echo ('10' == '10.0') ? "bool(true)\n" : "bool(false)\n";
echo ('abc' === 'abc') ? "bool(true)\n" : "bool(false)\n";
echo ('abc' == 'abc') ? "bool(true)\n" : "bool(false)\n";
echo ('abc' == 'def') ? "bool(true)\n" : "bool(false)\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
