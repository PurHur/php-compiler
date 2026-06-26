--TEST--
stdlib xml_parse() nested self-closing child (#12024, ext/xml/xml.c)
--FILE--
<?php
$parser = xml_parser_create();
echo (int) xml_parse($parser, '<root><item/></root>', true), "\n";
echo (int) xml_parse($parser, '<root><a/><b/></root>', true), "\n";
echo (int) xml_parse($parser, '<root><item></item></root>', true), "\n";
xml_parser_free($parser);
?>
--EXPECT--
1
1
1
