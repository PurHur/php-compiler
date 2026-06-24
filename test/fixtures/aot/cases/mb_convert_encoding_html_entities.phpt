--TEST--
AOT: mb_convert_encoding() HTML-ENTITIES (#11212)
--FILE--
<?php
echo mb_convert_encoding('über', 'HTML-ENTITIES', 'UTF-8'), "\n";
--EXPECT--
&uuml;ber
