--TEST--
SimpleXML: simplexml_load_file(null) returns false with warning (#19024, ext/simplexml/simplexml.c)
--FILE--
<?php
$result = @simplexml_load_file(null);
var_export($result === false);
echo "\n";
?>
--EXPECT--
true
