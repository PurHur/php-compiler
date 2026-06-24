--TEST--
stdlib mb_convert_encoding() HTML-ENTITIES pseudo-encoding (#11212)
--FILE--
<?php
echo mb_convert_encoding('über', 'HTML-ENTITIES', 'UTF-8'), "\n";
echo mb_convert_encoding('&uuml;ber', 'UTF-8', 'HTML-ENTITIES'), "\n";
--EXPECT--
&uuml;ber
über
