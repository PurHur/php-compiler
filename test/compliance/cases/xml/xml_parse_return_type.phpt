--TEST--
xml_parse() returns int 0/1 not bool (#18149, ext/xml/xml.c)
--FILE--
<?php
$parser = xml_parser_create();
$fail = xml_parse($parser, '<a><b></a>', true);
echo (int) is_int($fail), "\n";
echo $fail, "\n";
$parser2 = xml_parser_create();
$ok = xml_parse($parser2, '<root/>', true);
echo (int) is_int($ok), "\n";
echo $ok, "\n";
--EXPECT--
1
0
1
1
