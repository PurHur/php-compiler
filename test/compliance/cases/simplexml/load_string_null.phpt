--TEST--
SimpleXML: simplexml_load_string(null) returns false, no internal TypeError (#19014, ext/simplexml/simplexml.c)
--FILE--
<?php
$result = @simplexml_load_string(null);
var_export($result === false);
echo "\n";
?>
--EXPECT--
true
