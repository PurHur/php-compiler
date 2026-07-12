--TEST--
xml_parse() / xml_parse_into_struct() failures leave error_get_last() unset (#18135, ext/xml/xml.c)
--FILE--
<?php
$parser = xml_parser_create();
$ok = @xml_parse($parser, '<a><b></a>', true);
echo (int) (0 === $ok), "\n";
echo (int) (null === error_get_last()), "\n";

$parser2 = xml_parser_create();
$values = [];
$index = [];
$status = @xml_parse_into_struct($parser2, '<a><b></a>', $values, $index);
echo (int) (0 === $status), "\n";
echo (int) (null === error_get_last()), "\n";
--EXPECT--
1
1
1
1
