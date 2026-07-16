--TEST--
SimpleXML: simplexml_load_string accepts CDATA and coalesces into text (#19710, ext/simplexml/simplexml.c)
--FILE--
<?php
$s = @simplexml_load_string('<r><![CDATA[ab]]></r>');
var_export($s === false);
echo "\n";
echo (string) $s, "\n";

$mixed = @simplexml_load_string('<r>x<![CDATA[y]]>z</r>');
echo (string) $mixed, "\n";

$special = @simplexml_load_string('<r><![CDATA[a<b>]]></r>');
echo (string) $special, "\n";

$nested = @simplexml_load_string('<r><c><![CDATA[ab]]></c></r>');
echo (string) $nested->c, "\n";

$closeInCdata = @simplexml_load_string('<r><![CDATA[</r>]]></r>');
echo (string) $closeInCdata, "\n";
--EXPECT--
false
ab
xyz
a<b>
ab
</r>
