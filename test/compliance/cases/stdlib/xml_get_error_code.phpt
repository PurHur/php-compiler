--TEST--
stdlib xml_get_error_code() after successful parse returns 0 (#13295, ext/xml/xml.c)
--FILE--
<?php
$parser = xml_parser_create();
xml_parse($parser, '<root/>', true);
echo xml_get_error_code($parser), "\n";
--EXPECT--
0
